# Test Queries — Hotel Management Intelligence Agent

> The client can read the dashboard themselves. These queries are designed for cases that require **cross-table joins, trend analysis, projections, and multi-dimensional reasoning** that a static dashboard cannot provide.

---

## 1. Revenue Intelligence

| # | Query | Why not the dashboard |
|---|-------|----------------------|
| 1 | Compare le CA total de 2025 et 2026, par mois, et identifie la tendance. | Dashboard shows current month only. No year-over-year comparison. |
| 2 | Quel est le revenu moyen par chambre occupée, et comment a-t-il évolué sur les 12 derniers mois ? | Requires joining `chambre` + `reservation` + `charge` over time. Dashboard shows occupancy % but not revenue/chroom. |
| 3 | Projette le chiffre d'affaires annuel 2026 en se basant sur les tendances observées sur le premier semestre. | Projection requires trend calculation, not a static view. |
| 4 | Quel est le ratio entre les revenus restaurant et les revenus hébergement ? Est-ce que ce ratio est stable ou en évolution ? | Dashboard shows them on separate pages. No cross-domain ratio. |

## 2. Client Intelligence

| # | Query | Why not the dashboard |
|---|-------|----------------------|
| 5 | Quels sont les clients qui reviennent le plus souvent, et combien dépensent-ils en moyenne à chaque séjour ? | Dashboard shows present clients. No loyalty/return analysis. |
| 6 | Quelle est la durée moyenne de séjour par type de client (famille vs solo) et quel impact a-t-elle sur le panier moyen ? | Requires joining `client` + `reservation` + `charge` with segmentation. |
| 7 | Quel est le taux de no-show par mois, et quel est son impact sur le CA ? | Dashboard lists no-shows in notifications but no monthly rate or revenue impact. |

## 3. Operational Intelligence

| # | Query | Why not the dashboard |
|---|-------|----------------------|
| 8 | Quel est le taux d'occupation réel vs théorique, et combien de chambres sont restées non occupées un jour de pleine saison ? | Dashboard shows today's occupancy only. No historical occupancy rate. |
| 9 | Quel est le revenu par chambre disponible (RevPAR) et comment se compare-t-il à l'ADR moyen ? | RevPAR = total revenue / available rooms. ADR = total revenue / occupied rooms. Neither is shown. |
| 10 | Analyse la charge de travail par serveur : qui génère le plus de CA, et y a-t-il une corrélation entre le nombre de commandes et le montant moyen ? | Dashboard shows server report separately. No correlation analysis. |

## 4. Seasonal & Predictive

| # | Query | Why not the dashboard |
|---|-------|----------------------|
| 11 | Quels sont les mois les plus faibles et quels types de charges baissent en priorité ? Peux-tu suggérer des ajustements ? | Requires identifying low-season patterns across charge types. Dashboard has no seasonal view. |
| 12 | Montre-moi la saisonnalité de l'occupation par jour de la semaine — est-ce que le weekend est vraiment plus rentable ? | Requires aggregating reservations by day-of-week over months. |
| 13 | Si on maintient le trend actuel, quel sera le CA total du restaurant en fin d'année 2026 ? | Predictive, requires extrapolation from monthly data. |

## 5. Cross-Dimensional Analysis

| # | Query | Why not the dashboard |
|---|-------|----------------------|
| 14 | Croise le chiffre d'affaires par point de vente avec le taux d'occupation : est-ce que les jours de forte occupation génèrent aussi plus de revenus restaurant ? | Dashboard shows occupancy and revenue on separate pages. No cross-domain correlation. |
| 15 | Quel est le profil de dépense des clients qui séjournent plus de 5 nuits vs ceux qui séjournent moins de 3 nuits ? | Requires segmenting clients by stay duration and comparing spending patterns. |
