"""Chart tool: build interactive vector chart specs (bar, line, pie, scatter,
etc.) from DataFrame data, rendered client-side by the UI."""

import json
from typing import Any, Dict, List, Optional, Tuple

import pandas as pd

from .base import BaseTool, StandardToolResult, build_envelope

_CHART_MAX_SERIES = 8
_CHART_MAX_CATEGORIES = 20
_CHART_MIN_NUMERIC_RATIO = 0.5
_CHART_RENDER_BUDGET = 500

_CHART_TYPES = ("bar", "line", "area", "scatter", "pie", "donut", "histogram", "box")

_CHART_REQUIREMENTS: Dict[str, Dict[str, Any]] = {
    "bar":       {"y": "multi",  "group_by": "optional",  "numeric_y": True,  "stack_eligible": True},
    "line":      {"y": "multi",  "group_by": "optional",  "numeric_y": True,  "stack_eligible": False},
    "area":      {"y": "multi",  "group_by": "optional",  "numeric_y": True,  "stack_eligible": True},
    "scatter":   {"y": "multi",  "group_by": "optional",  "numeric_y": True,  "stack_eligible": False},
    "pie":       {"y": "single", "group_by": "forbidden", "numeric_y": True,  "stack_eligible": False},
    "donut":     {"y": "single", "group_by": "forbidden", "numeric_y": True,  "stack_eligible": False},
    "histogram": {"y": "none",   "group_by": "optional",  "numeric_y": False, "stack_eligible": False},
    "box":       {"y": "multi",  "group_by": "optional",  "numeric_y": True,  "stack_eligible": False},
}


def _chart_validate_shape(chart_type: str, x_col: str, y_cols: List[str], group_col: Optional[str],
                           stacked: bool) -> Tuple[bool, List[str]]:
    errors: List[str] = []
    req = _CHART_REQUIREMENTS.get(chart_type)
    if req is None:
        return False, [f"Unknown chart_type '{chart_type}'. Must be one of {list(_CHART_REQUIREMENTS)}."]

    if not x_col:
        errors.append("Provide an 'x' column.")

    if req["y"] == "none":
        if y_cols:
            errors.append(f"chart_type '{chart_type}' does not use 'y' — it counts occurrences of 'x' directly. Remove 'y'.")
    elif req["y"] == "single":
        if len(y_cols) == 0:
            errors.append(f"chart_type '{chart_type}' requires exactly one 'y' column.")
        elif len(y_cols) > 1:
            errors.append(f"chart_type '{chart_type}' takes exactly one 'y' column, got {len(y_cols)}: {y_cols}. Aggregate first if you need to compare multiple measures.")
    else:
        if len(y_cols) == 0:
            errors.append(f"chart_type '{chart_type}' requires at least one 'y' column.")
        elif len(y_cols) > _CHART_MAX_SERIES:
            errors.append(f"Too many 'y' columns ({len(y_cols)}); max is {_CHART_MAX_SERIES}. Pick the most relevant series or reshape with group_by instead.")

    if req["group_by"] == "forbidden" and group_col:
        errors.append(f"chart_type '{chart_type}' does not support 'group_by' (there's only one series). Remove it or use 'x' for the category.")

    if stacked and not req["stack_eligible"]:
        errors.append(f"'stacked' is not supported for chart_type '{chart_type}'.")

    if stacked and not group_col and len(y_cols) < 2:
        errors.append("'stacked' requires 'group_by' (nothing to stack with a single series) or multiple 'y' columns.")

    return (len(errors) == 0), errors


def _chart_coerce_numeric(df: pd.DataFrame, cols: List[str]) -> Tuple[pd.DataFrame, List[str]]:
    warnings: List[str] = []
    out = df.copy()
    for col in cols:
        if col not in out.columns:
            continue
        original = out[col]
        if pd.api.types.is_numeric_dtype(original):
            continue
        before_non_null = original.notna().sum()
        coerced = pd.to_numeric(original, errors="coerce")
        after_non_null = coerced.notna().sum()
        if before_non_null > 0:
            lost_ratio = 1 - (after_non_null / before_non_null)
            if lost_ratio > _CHART_MIN_NUMERIC_RATIO:
                warnings.append(
                    f"Column '{col}' is not numeric and only {after_non_null}/{before_non_null} "
                    f"values could be converted — check this is the right column for a value axis."
                )
            elif lost_ratio > 0:
                warnings.append(f"Column '{col}': {before_non_null - after_non_null} non-numeric value(s) dropped during charting.")
        out[col] = coerced
    return out, warnings


def _chart_cap_categories(df: pd.DataFrame, category_col: str, value_cols: List[str],
                           max_categories: int = _CHART_MAX_CATEGORIES) -> Tuple[pd.DataFrame, List[str], int]:
    warnings: List[str] = []
    if category_col not in df.columns:
        return df, warnings, 0
    n_unique = df[category_col].nunique(dropna=False)
    if n_unique <= max_categories:
        return df, warnings, 0

    rank_col = value_cols[0] if value_cols and value_cols[0] in df.columns else None
    if rank_col is not None:
        totals = df.groupby(category_col, dropna=False)[rank_col].sum().sort_values(ascending=False)
    else:
        totals = df[category_col].value_counts(dropna=False)
    keep = set(totals.index[: max_categories - 1])

    kept_mask = df[category_col].isin(keep)
    kept_df = df[kept_mask]
    other_df = df[~kept_mask]
    if not other_df.empty:
        agg: Dict[str, Any] = {}
        for col in df.columns:
            if col == category_col:
                continue
            if col in value_cols and pd.api.types.is_numeric_dtype(other_df[col]):
                agg[col] = other_df[col].sum()
            else:
                agg[col] = other_df[col].iloc[0]
        other_row = {category_col: "Other"}
        other_row.update(agg)
        kept_df = pd.concat([kept_df, pd.DataFrame([other_row])], ignore_index=True)
        warnings.append(
            f"Column '{category_col}' has {n_unique} distinct values; the smallest "
            f"{n_unique - (max_categories - 1)} were combined into 'Other' to keep the chart readable."
        )
    rollup_count = max(0, n_unique - (max_categories - 1)) if not other_df.empty else 0
    return kept_df, warnings, rollup_count


def _chart_sort_and_limit(df: pd.DataFrame, x_col: str, y_cols: List[str], sort_by: Optional[str],
                           sort_order: str, limit: Optional[int]) -> Tuple[pd.DataFrame, List[str], bool]:
    warnings: List[str] = []
    out = df
    if sort_by in ("x", "y"):
        col = x_col if sort_by == "x" else (y_cols[0] if y_cols else x_col)
        if col in out.columns:
            ascending = sort_order != "desc"
            try:
                out = out.sort_values(by=col, ascending=ascending, kind="stable")
            except Exception as e:
                warnings.append(f"Could not sort by '{col}': {e}")
    limited = bool(limit is not None and limit > 0 and len(out) > limit)
    if limited:
        out = out.head(limit)
        warnings.append(f"Limited to top {limit} rows.")
    return out, warnings, limited


def _chart_bin_histogram(df: pd.DataFrame, x_col: str, max_bins: int = 15) -> Tuple[pd.DataFrame, List[str]]:
    """Bin a numeric column into histogram bins and return frequency counts."""
    warnings: List[str] = []
    vals = pd.to_numeric(df[x_col], errors="coerce").dropna()
    if len(vals) == 0:
        return df, ["No numeric data for histogram"]
    n_unique = vals.nunique()
    try:
        binned = pd.cut(vals, bins=min(max_bins, n_unique), include_lowest=True)
    except Exception:
        return df, ["Could not create histogram bins"]
    freq = binned.value_counts().sort_index()
    result = pd.DataFrame({
        x_col: [str(b) for b in freq.index],
        "count": freq.values,
    })
    return result, warnings


def _chart_detect_axis_type(series: pd.Series) -> str:
    if pd.api.types.is_datetime64_any_dtype(series):
        return "temporal"
    if pd.api.types.is_numeric_dtype(series):
        return "numeric"
    sample = series.dropna().astype(str).head(10)
    if len(sample) > 0:
        try:
            parsed = pd.to_datetime(sample, errors="coerce")
            if parsed.notna().mean() > 0.8:
                return "temporal"
        except Exception:
            pass
    return "category"


class ChartTool(BaseTool):
    name = "create_chart_spec"
    description = (
        "Create an interactive vector visualization specification. Accepts any DataFrame "
        "name returned by execute_sql or run_python — no intermediate run_python step is "
        "needed. Specify the DataFrame name, chart type, and column mappings. Supports bar, "
        "line, area, scatter, pie, donut, histogram, and box charts, plus stacking, "
        "sorting/top-N limiting, and axis labels. Returns a unique chart token (e.g. [CHART_0]) "
        "to embed in your final answer. Do NOT use matplotlib or write markdown image syntax "
        "— this tool produces a live, interactive chart instead."
    )
    input_schema = {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "description": "REQUIRED. One sentence describing what this call does.",
            },
            "df_name": {
                "type": "string",
                "description": "Name of an existing Pandas DataFrame in the session containing the data to plot.",
            },
            "chart_type": {
                "type": "string",
                "enum": list(_CHART_TYPES),
                "description": (
                    "bar/line/area: trend or category comparison over 'x' with one or more 'y' series. "
                    "scatter: correlation between 'x' and 'y' (numeric-numeric). "
                    "pie/donut: one category column ('x') and exactly one value column ('y'). "
                    "histogram: distribution of a single numeric column ('x'); no 'y'. "
                    "box: distribution of numeric 'y' column(s), optionally grouped by 'x'/'group_by'."
                ),
            },
            "x": {
                "type": "string",
                "description": "DataFrame column mapped to the X-axis (category/slice axis for pie/donut, the value column for histogram).",
            },
            "y": {
                "type": "array",
                "items": {"type": "string"},
                "description": "Column(s) mapped to the Y-axis / value axis. Exactly one for pie/donut, omit for histogram, up to 8 for others.",
            },
            "group_by": {
                "type": "string",
                "description": "Optional column to split the series by (maps to colors/legend). Not supported for pie/donut.",
            },
            "stacked": {
                "type": "boolean",
                "description": "Stack series instead of clustering them side by side. Only valid for bar/area, and only with group_by or multiple 'y' columns. Default false.",
            },
            "sort_by": {
                "type": "string",
                "enum": ["none", "x", "y"],
                "description": "Sort rows by the x column or the first y column before plotting (and before applying 'limit'). Default 'none' (keep DataFrame order).",
            },
            "sort_order": {
                "type": "string",
                "enum": ["asc", "desc"],
                "description": "Sort direction when sort_by is set. Default 'desc'.",
            },
            "limit": {
                "type": "integer",
                "description": "Keep only the top N rows after sorting (e.g. 'top 10 by revenue' = sort_by:'y', sort_order:'desc', limit:10).",
            },
            "aggregate": {
                "type": "string",
                "enum": ["sum", "mean", "min", "max", "count"],
                "description": "Explicit aggregation function to apply when x has duplicate values. Required for line/bar/area charts when the DataFrame has multiple rows per x value. Prevents silent overplotting. Example: aggregate='sum' groups by x (and group_by if set) and sums each y column.",
            },
            "drop_nulls": {
                "type": "boolean",
                "description": "If true, rows with null values in x or y columns are dropped before charting. Default false — the tool errors on nulls unless this flag is explicitly set to prevent silent data loss.",
            },
            "x_label": {
                "type": "string",
                "description": "Optional axis label to display in place of the raw column name.",
            },
            "y_label": {
                "type": "string",
                "description": "Optional axis label to display in place of the raw column name(s).",
            },
            "y_labels": {
                "type": "object",
                "additionalProperties": {"type": "string"},
                "description": "Optional mapping from y column name to a human-readable legend label (e.g. {\"nb_arrivees\": \"Arrivées\", \"nb_reservations\": \"Réservations créées\"}). Unknown keys are ignored.",
            },
            "title": {
                "type": "string",
                "description": "Title of the chart.",
            },
        },
        "required": ["df_name", "chart_type", "x"],
    }

    @staticmethod
    def _as_str_list(value: Any) -> List[str]:
        if value is None:
            return []
        if isinstance(value, str):
            return [value] if value.strip() else []
        if isinstance(value, list):
            return [str(v).strip() for v in value if str(v).strip()]
        return [str(value)]

    async def run(self, inputs: Dict[str, Any], context: Dict[str, Any]) -> Any:
        df_name = (inputs.get("df_name") or "").strip()
        chart_type = (inputs.get("chart_type") or "").strip().lower()
        x_col = (inputs.get("x") or "").strip()
        y_cols = self._as_str_list(inputs.get("y"))
        group_col = (inputs.get("group_by") or "").strip() or None
        title = (inputs.get("title") or "").strip()
        stacked = inputs.get("stacked") in (True, "true", 1)
        sort_by = (inputs.get("sort_by") or "none").strip().lower()
        sort_by = sort_by if sort_by in ("x", "y") else None
        sort_order = (inputs.get("sort_order") or "desc").strip().lower()
        sort_order = sort_order if sort_order in ("asc", "desc") else "desc"
        limit = inputs.get("limit")
        try:
            limit = int(limit) if limit is not None else None
        except (TypeError, ValueError):
            limit = None
        x_label = (inputs.get("x_label") or "").strip() or None
        y_label = (inputs.get("y_label") or "").strip() or None
        y_labels_raw = inputs.get("y_labels") or {}
        y_labels = {str(k): str(v) for k, v in y_labels_raw.items() if v is not None} if isinstance(y_labels_raw, dict) else {}

        session_manager = context.get("session_manager")
        session_id = context["session_id"]
        client_id = context["client_id"]

        if session_manager is None:
            return StandardToolResult(
                status="infra_error",
                tool="create_chart_spec",
                summary="Session storage unavailable",
                data=build_envelope("error", error="Session storage unavailable; cannot read DataFrame data.", error_kind="infra"),
                ui_data={},
            )

        ok, shape_errors = _chart_validate_shape(chart_type, x_col, y_cols, group_col, stacked)
        if not ok:
            return StandardToolResult(
                status="user_error",
                tool="create_chart_spec",
                summary="; ".join(shape_errors),
                data=build_envelope("error", error="; ".join(shape_errors), error_kind="input", hint="Adjust chart_type/x/y/group_by/stacked and retry."),
                ui_data={},
                repair_hint="Adjust chart_type/x/y/group_by/stacked and retry.",
            )

        try:
            df = await session_manager.store.get(client_id, session_id, df_name)
        except Exception as e:
            return StandardToolResult(
                status="infra_error",
                tool="create_chart_spec",
                summary=f"Failed to read DataFrame: {e}",
                data=build_envelope("error", error=f"Failed to read session DataFrames: {e}", error_kind="infra"),
                ui_data={},
            )

        if df is None:
            try:
                available_names = sorted(await session_manager.store.list(client_id, session_id))
            except Exception:
                available_names = []
            available = ", ".join(available_names) or "(none)"
            return StandardToolResult(
                status="user_error",
                tool="create_chart_spec",
                summary=f"DataFrame '{df_name}' not found",
                data=build_envelope("error", error=f"DataFrame '{df_name}' does not exist. Available: {available}", error_kind="input", available_dfs=available_names),
                ui_data={},
                repair_hint=f"Use an existing DataFrame name. Available: {available}",
            )

        missing = [c for c in [x_col, *y_cols, group_col] if c and c not in df.columns]
        if missing:
            return StandardToolResult(
                status="user_error",
                tool="create_chart_spec",
                summary=f"Column(s) not found: {missing}",
                data=build_envelope("error", error=f"Column(s) not found in '{df_name}': {missing}. Available: {list(df.columns)}", error_kind="input", available_columns=list(df.columns)),
                ui_data={},
                repair_hint=f"Available columns: {list(df.columns)}. Pick from these.",
            )

        aggregate = inputs.get("aggregate") or None
        drop_nulls = inputs.get("drop_nulls") in (True, "true", 1)

        check_cols = [x_col, *y_cols] if chart_type != "histogram" else [x_col]
        null_counts = df[check_cols].isna().sum()
        null_cols = {c: int(null_counts[c]) for c in check_cols if null_counts[c] > 0}
        if null_cols and not drop_nulls:
            return StandardToolResult(
                status="user_error",
                tool="create_chart_spec",
                summary=f"Null values in columns: {null_cols}",
                data=build_envelope("error", error=f"Null values found in chart columns: {null_cols}. Use drop_nulls=true if intentional removal is acceptable.", error_kind="input", hint="Pass drop_nulls=true to drop rows with null values, or fix the upstream SQL to exclude nulls with WHERE ... IS NOT NULL."),
                ui_data={},
                repair_hint="Pass drop_nulls=true to drop rows with null values.",
            )

        if chart_type in ("line", "bar", "area") and not aggregate:
            dup_count = int(df[x_col].duplicated(keep=False).sum())
            if dup_count > 0:
                return StandardToolResult(
                    status="user_error",
                    tool="create_chart_spec",
                    summary=f"Duplicate x values ({dup_count} rows) need aggregation",
                    data=build_envelope("error", error=f"Column '{x_col}' has {dup_count} duplicate values (out of {len(df)} rows). Without explicit aggregation these would overplot silently. Pass aggregate='sum' | 'mean' | 'min' | 'max' | 'count' or fix the SQL with GROUP BY.", error_kind="input", hint=f"Pass aggregate='sum' to group by {x_col} and aggregate each y column."),
                    ui_data={},
                    repair_hint=f"Pass aggregate='sum' to group by {x_col} and aggregate each y column.",
                )

        if drop_nulls:
            df = df.dropna(subset=check_cols)

        warnings: List[str] = []
        req = _CHART_REQUIREMENTS[chart_type]

        numeric_cols = [x_col] if chart_type == "histogram" else (y_cols if req["numeric_y"] else [])
        if numeric_cols:
            df, coerce_warnings = _chart_coerce_numeric(df, numeric_cols)
            warnings.extend(coerce_warnings)
            unusable = [c for c in numeric_cols if df[c].notna().sum() == 0]
            if unusable:
                return StandardToolResult(
                    status="user_error",
                    tool="create_chart_spec",
                    summary=f"Column(s) {unusable} not numeric",
                    data=build_envelope("error", error=f"Column(s) {unusable} could not be interpreted as numeric values for chart_type '{chart_type}'.", error_kind="input", hint="Choose a numeric column, or aggregate/convert it first with run_python."),
                    ui_data={},
                    repair_hint="Choose a numeric column, or aggregate/convert it first with run_python.",
                )

        if aggregate:
            group_cols = [x_col]
            if group_col:
                group_cols.append(group_col)
            agg_map = dict.fromkeys(y_cols, aggregate)
            try:
                df = df.groupby(group_cols).agg(agg_map).reset_index()
            except Exception as e:
                return StandardToolResult(
                    status="user_error",
                    tool="create_chart_spec",
                    summary=f"Aggregation failed: {e}",
                    data=build_envelope("error", error=f"Aggregation failed: {e}", error_kind="sandbox", hint="Ensure all y columns are numeric. If not, convert them first with run_python."),
                    ui_data={},
                    repair_hint="Ensure all y columns are numeric. Convert them first with run_python if needed.",
                )

        category_col = x_col if chart_type in ("pie", "donut") else (group_col or x_col)
        cap_rollup = 0
        if chart_type in ("pie", "donut") or group_col or chart_type == "bar":
            df, cap_warnings, cap_rollup = _chart_cap_categories(df, category_col, y_cols)
            warnings.extend(cap_warnings)

        df, sort_warnings, chart_limited = _chart_sort_and_limit(df, x_col, y_cols, sort_by, sort_order, limit)
        warnings.extend(sort_warnings)

        if chart_type == "histogram":
            df, hist_warnings = _chart_bin_histogram(df, x_col)
            warnings.extend(hist_warnings)
            x_type = "category"
        else:
            x_type = _chart_detect_axis_type(df[x_col]) if x_col in df.columns else "category"

        session_dfs = context.get("session_dataframes", {})
        base_meta = session_dfs.get(df_name, {})
        truncated = base_meta.get("truncated", False) if isinstance(base_meta, dict) else False

        _step_id = context.get("_current_step_id", "")
        chart_suffix = _step_id.rsplit("_", 1)[-1] or "0"
        chart_id = f"CHART_{chart_suffix}"
        token = f"[{chart_id}]"

        y_cols_for_spec = ["count"] if chart_type == "histogram" else y_cols
        data_cols = [c for c in [x_col, *y_cols_for_spec, group_col] if c and c in df.columns]
        chart_data = json.loads(df[data_cols].to_json(orient="records", date_format="iso"))
        true_n = len(chart_data)
        decimated = False
        if true_n > _CHART_RENDER_BUDGET:
            step = (true_n + _CHART_RENDER_BUDGET - 1) // _CHART_RENDER_BUDGET
            chart_data = chart_data[::step]
            decimated = True

        chart_spec = {
            "chart_id": chart_id,
            "token": token,
            "chart_type": chart_type,
            "title": title,
            "x": x_col,
            "x_label": x_label or x_col,
            "x_type": x_type,
            "y": y_cols_for_spec,
            "y_label": y_label or (y_cols_for_spec[0] if len(y_cols_for_spec) == 1 else None),
            "y_labels": {c: y_labels.get(c, c.title()) for c in y_cols_for_spec},
            "group_by": group_col,
            "stacked": stacked,
            "df_name": df_name,
            "data": chart_data,
            "true_row_count": true_n,
            "render_count": len(chart_data),
            "decimated": decimated,
            "meta": {
                "row_count": len(df),
                "truncated": truncated,
                "category_rollup": cap_rollup,
                "limited": chart_limited,
                "warnings": warnings,
            },
        }

        message = f"Chart generated. Embed this token EXACTLY on its own line in your final answer to display the chart: {token}"
        if warnings:
            message += " Note: " + " ".join(warnings)

        return StandardToolResult(
            status="success",
            tool="create_chart_spec",
            summary=f"Generated {chart_type} chart {token}",
            data=build_envelope("success", token=token, message=message, chart_spec=chart_spec),
            ui_data={"token": token, "chart_spec": chart_spec},
        )
