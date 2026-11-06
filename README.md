# Forecast import for the Kimai Weekly Planner

Moving from [Harvest Forecast](https://www.getharvest.com/forecast) to [Kimai](https://www.kimai.org/)? This plugin brings your team's plan with you. A super admin connects to Forecast, reviews how its people and projects match Kimai's users, customers and projects, and imports the planning, time off and tentative work into the [Weekly Planner](https://github.com/happiness/PlannerBundle). Nobody has to re-enter the next few months by hand.

> **Status: early development.** Not ready for use yet. Watch the repository for the first release.

## What it will do

- Connect to Forecast with a Harvest personal access token and your Forecast account ID. The token is used for the import only and never stored.
- Match people by email, and customers and projects by name, with Forecast's Harvest project link as an extra hint.
- Show a preview before anything is written: what matched, what didn't, and what will be created. Map or skip anything unmatched by hand.
- Import assignments as plannings, Forecast time off as planner time off, and tentative work as tentative planning.
- Run again safely: each planning remembers its Forecast assignment, so a second run updates rather than duplicates. Useful for a final top-up on cutover day.
- Offer a console command for large accounts, alongside the screen.

## Requirements

- Kimai 2.60 or later
- [PlannerBundle](https://github.com/happiness/PlannerBundle) installed
- Outbound HTTPS from the Kimai server to `api.forecastapp.com`

## About the Forecast API

Forecast has no official public API. This plugin uses the same endpoints Forecast's own web app calls, as the community libraries [xvilo/harvest-forecast](https://github.com/xvilo/harvest-forecast) and [forecast-promise](https://github.com/dan1elhughes/forecast-promise) do. It reads only and never changes anything in Forecast. If Forecast changes its API, the import stops with a clear error instead of importing half a plan.

## Licence

[AGPL-3.0-or-later](LICENSE), the same as Kimai and PlannerBundle.

Built by [Angry Creative](https://angrycreative.se), who moved from Forecast to Kimai themselves.
