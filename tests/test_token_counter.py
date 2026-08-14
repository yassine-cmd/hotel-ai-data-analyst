"""Tests for the TokenCounter in agent.core — accumulation, summaries, and the
dict-deserialization path used when resuming a persisted session.
"""
from types import SimpleNamespace

import pytest

from agent.core import TokenCounter


class _FakeUsage:
    def __init__(self, prompt=10, completion=20, total=30, thinking=5,
                 cache_hit=2, cache_miss=None, cost=0.0, model_extra=None):
        self.prompt_tokens = prompt
        self.completion_tokens = completion
        self.total_tokens = total
        self.completion_tokens_details = SimpleNamespace(reasoning_tokens=thinking)
        self.prompt_cache_hit_tokens = cache_hit
        self.prompt_cache_miss_tokens = cache_miss if cache_miss is not None else max(0, prompt - cache_hit)
        if model_extra is not None:
            self.cost = None
            self.model_extra = model_extra
        else:
            self.cost = cost


def test_record_step_accumulates():
    tc = TokenCounter(context_limit=1000)
    tc.record_step(_FakeUsage())
    assert tc.step_prompt == 10
    assert tc.total_prompt == 10
    assert tc.total_completion == 20
    assert tc.total_thinking == 5
    assert tc.total_tokens == 30
    # second step accumulates
    tc.record_step(_FakeUsage(prompt=5, completion=5, total=10))
    assert tc.total_prompt == 15
    assert tc.total_tokens == 40


def test_usage_pct():
    tc = TokenCounter(context_limit=100)
    tc.record_step(_FakeUsage(prompt=50))
    assert tc.usage_pct == 0.5
    tc2 = TokenCounter(context_limit=0)
    assert tc2.usage_pct == 0.0


def test_cache_hit_ratio():
    tc = TokenCounter(context_limit=1000)
    tc.record_step(_FakeUsage(prompt=100, cache_hit=70))
    assert tc.cache_hit_ratio == 0.7
    # cumulative across steps
    tc.record_step(_FakeUsage(prompt=50, cache_hit=20))
    assert tc.cache_hit_ratio == 0.6
    # no usage recorded yet -> 0.0, no ZeroDivisionError
    tc2 = TokenCounter()
    assert tc2.cache_hit_ratio == 0.0


def test_summaries():
    tc = TokenCounter()
    tc.record_step(_FakeUsage())
    step_s = tc.step_summary()
    cum_s = tc.cumulative_summary()
    assert "prompt=10" in step_s
    assert "prompt=10" in cum_s
    assert "thinking=5" in cum_s
    # Step summary should include "completion" AND "total" for the current step only
    assert "completion=20" in step_s
    assert "total=30" in step_s
    # Cumulative summary for zero steps returns zeros
    tc2 = TokenCounter()
    assert "prompt=0" in tc2.cumulative_summary()


def test_deserialize_from_dict():
    data = {
        "context_limit": 12345,
        "total_prompt": 100,
        "total_completion": 200,
        "total_tokens": 300,
        "step_prompt": 10,
    }
    tc = TokenCounter(**data)
    assert isinstance(tc, TokenCounter)
    assert tc.context_limit == 12345
    assert tc.total_prompt == 100
    assert tc.total_tokens == 300
    # still functional after reconstruction
    tc.record_step(_FakeUsage())
    assert tc.total_prompt == 110


def test_deserialize_missing_keys_default():
    tc = TokenCounter(**{"total_tokens": 7})
    assert tc.context_limit == 200_000
    assert tc.total_tokens == 7


def test_record_step_openai_style_cached_tokens():
    """Gateways like OpenCode Zen report cache reads only via the OpenAI-style
    prompt_tokens_details.cached_tokens (not DeepSeek's prompt_cache_hit/miss
    tokens). The counter must fall back to it: hit=cached, miss=prompt-cached."""
    usage = SimpleNamespace(
        prompt_tokens=1000,
        completion_tokens=50,
        total_tokens=1050,
        completion_tokens_details=None,
    )
    # DeepSeek-style fields absent -> hit 0, entire prompt billed as a miss
    tc = TokenCounter(context_limit=200000)
    tc.record_step(usage)
    assert tc.step_cache_hit == 0
    assert tc.step_cache_miss == 1000
    assert tc.cache_hit_ratio == 0.0

    # OpenAI-style field present -> fallback kicks in
    tc2 = TokenCounter(context_limit=200000)
    usage2 = SimpleNamespace(
        prompt_tokens=1000,
        completion_tokens=50,
        total_tokens=1050,
        completion_tokens_details=None,
        prompt_tokens_details=SimpleNamespace(cached_tokens=700),
    )
    tc2.record_step(usage2)
    assert tc2.step_cache_hit == 700
    assert tc2.step_cache_miss == 300
    assert tc2.step_cache_hit + tc2.step_cache_miss == tc2.step_prompt
    assert tc2.cache_hit_ratio == 0.7


def test_record_step_captures_gateway_cost():
    """The gateway-reported billed cost (OpenRouter usage.cost) is the
    authoritative figure recorded per step and accumulated over the session."""
    tc = TokenCounter(context_limit=1000)
    tc.record_step(_FakeUsage(cost=0.000123))
    assert tc.step_cost == 0.000123
    assert tc.total_cost == 0.000123
    # second step accumulates
    tc.record_step(_FakeUsage(prompt=5, completion=5, total=10, cost=0.000077))
    assert tc.total_cost == 0.0002


def test_record_step_cost_falls_back_to_model_extra():
    """OpenRouter surfaces usage.cost in the final streamed chunk, but some
    SDKs nest it under model_extra instead; the counter must honor that and
    never crash when `cost` is absent."""
    tc = TokenCounter(context_limit=1000)
    tc.record_step(_FakeUsage(cost=0.0, model_extra={"cost": 0.5}))
    assert tc.step_cost == 0.5
    assert tc.total_cost == 0.5
    # missing cost entirely -> 0, no exception
    tc2 = TokenCounter(context_limit=1000)
    tc2.record_step(SimpleNamespace(
        prompt_tokens=10, completion_tokens=20, total_tokens=30,
        completion_tokens_details=SimpleNamespace(reasoning_tokens=5),
    ))
    assert tc2.step_cost == 0.0
    assert tc2.total_cost == 0.0


def test_summaries_and_persistence_include_cost():
    tc = TokenCounter(context_limit=1000)
    tc.record_step(_FakeUsage(cost=0.0001))
    assert "cost=0.000100" in tc.step_summary()
    assert "cost=0.000100" in tc.cumulative_summary()

    data = {
        "context_limit": 1000,
        "total_prompt": 10,
        "total_completion": 20,
        "total_tokens": 30,
        "total_cost": 0.0001,
    }
    tc2 = TokenCounter(**data)
    assert tc2.total_cost == 0.0001
    tc2.record_step(_FakeUsage(prompt=5, completion=5, total=10, cost=0.00005))
    assert tc2.total_cost == pytest.approx(0.00015)
