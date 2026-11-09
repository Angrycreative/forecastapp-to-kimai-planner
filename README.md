# Forecast import for the Kimai Weekly Planner

Moving from [Harvest Forecast](https://www.getharvest.com/forecast) to [Kimai](https://www.kimai.org/)? This plugin brings your team's plan with you. Whoever runs the import (super admins, unless you give the permission to others) reads Forecast once, decides where its people and projects belong in Kimai, checks a preview and imports the planning, time off and tentative work into the [Weekly Planner](https://github.com/happiness/PlannerBundle). Nobody has to re-enter the coming months by hand, and you can run it again until the day you switch Forecast off.

Built by [Angry Creative](https://angrycreative.se) for our own move from Forecast to Kimai, and shared so yours is easier.

## What it does

- **Reads Forecast, never writes to it.** You enter a Harvest personal access token and your Forecast account ID. The plugin only ever reads: it cannot change anything in Forecast. Tick "Remember" to keep the token, encrypted, for the next read, and clear it with one button when you are done; the screen reminds you after an import.
- **Matches people automatically.** Forecast people are matched to Kimai users by email address. You can change any match, or leave someone out.
- **Suggests where each project goes.** Project names rarely survive a move unchanged ("Acme / Acme Marketing" in Forecast, "Acme Ltd / Acme Marketing Services" in Kimai), so the plugin scores how alike the customer and project names are and suggests the closest Kimai project. Strong suggestions are ticked for you to accept in one go; you confirm or change the rest. A Forecast project can also go to a customer without a project.
- **Brings time off across as time off.** Forecast's "Time Off" project becomes planner time off, whole days included.
- **Lets you say what "tentative" means.** Forecast has no tentative flag, so you choose project tags (such as "not signed") whose assignments are imported as tentative planning.
- **Respects working days.** Forecast books working days only; a planning in Kimai covers every day between its dates. Assignments are cut into runs of each person's working days, from their Kimai work contract and, where the planner knows them, public holidays. For someone without a work contract in Kimai yet, Forecast's own working days for that person are used, and the preview says so. When an assignment is cut around days off, its parts form one series in the planner, as they would if you had planned it there; a planning in one piece is an ordinary planning.
- **Shows you everything before it writes anything.** Reading Forecast only copies its plan in for review, and says what it found and what changed since the previous read. The preview then lists, per person, every assignment with its dates, hours and what the import will do with it (new, changed, to remove or already up to date), plus anything left out and why. Only "Import planning" on that last step writes to the planner.
- **Is safe to run again.** Each planning remembers the Forecast assignment it came from. Running again:
  - creates what is new in Forecast;
  - leaves an assignment alone when it has not changed in Forecast or in your mapping, whatever the import dates. Edits made to its plannings in Kimai stay, including a planning split or deleted there;
  - replaces the plannings of an assignment that changed in Forecast or in your mapping, including halves split off in the planner, so nothing is planned twice;
  - removes the plannings of assignments deleted in Forecast or skipped since;
  - leaves an assignment it cannot resolve this time (unmapped) as an earlier run created it.

  Only the chosen dates are touched: plannings before the start date stay as history.
- **Can start over.** One button removes every imported planning, including halves split off in the planner. Plannings made in Kimai, and your mapping decisions, stay.

## Requirements

- Kimai 2.60 or later
- [PlannerBundle](https://github.com/happiness/PlannerBundle) installed. Time off and tentative planning need a planner version that has them; with an older planner those assignments are reported and imported as far as it allows.
- Outbound HTTPS from the Kimai server to `api.forecastapp.com`
- A Harvest personal access token for someone with access to the Forecast account ([id.getharvest.com/developers](https://id.getharvest.com/developers))

## Installation

1. Copy or clone the plugin into `var/plugins/ForecastImportBundle` (the directory name matters).
2. Create its database tables: `bin/console kimai:plugins --install`
3. Clear the cache: `bin/console cache:clear`

Super admins get the `import_forecast_planner` permission and find **Forecast import** under **System**. Anyone else who should run the import can be given it under **System > Role permissions**, where it is listed in the **Weekly Planner** section. Give it with care: importing writes planning for everyone. The console command needs no permission, since it runs with server access.

## Using it

A step bar on every page shows where you are and how far each step is, and a collapsible guide on the first step explains each one.

1. **Read Forecast.** Enter the account ID (the number in `forecastapp.com/<account ID>/`), the token and the dates to import. Today onwards is usually right: nothing before the start date is created. Reading changes nothing in the planner. The dates you chose stay in the form for the next read.
2. **People.** Check the automatic matches and decide about anyone unmatched. Placeholders are skipped by default because the planner plans people, but you can give one to a user.
3. **Projects.** Accept the ticked suggestions, then go through the rest. Unmapped projects are listed first.
4. **Tentative work.** Tick the tags that mean "not confirmed yet".
5. **Preview and import.** Check what will happen per person and anything worth checking, then press "Import planning".

Run it again whenever Forecast changes: read Forecast again, review anything new, preview, import.

### From the command line

For large accounts or a scripted cutover. The mapping decisions made on the screen are used; people are still matched by email without them.

```bash
# the token comes from FORECAST_TOKEN, else the token saved on the screen, else it is asked for without echoing it
FORECAST_TOKEN=... bin/console kimai:forecast-import --fetch --account-id=123456 --dry-run
bin/console kimai:forecast-import --accept-suggestions   # use the last data read, accept confident suggestions, import
```

Options: `--fetch`, `--account-id`, `--begin` and `--end` (any date PHP understands, default today to a year ahead), `--accept-suggestions`, `--dry-run`.

## About the Forecast API

Forecast has no official public API. This plugin uses the endpoints Forecast's own web app calls, as the community libraries [xvilo/harvest-forecast](https://github.com/xvilo/harvest-forecast) and [forecast-promise](https://github.com/dan1elhughes/forecast-promise) do: `/whoami`, `/people`, `/placeholders`, `/clients`, `/projects` and `/assignments` (in chunks of 180 days, since Forecast requires a date range). Every field the import relies on is checked when Forecast answers, so if Forecast changes its API the import stops with a clear message instead of importing half a plan.

What we learned from a real account, in case it saves you time:

- An assignment's `allocation` is seconds per day; `null` means a whole day and is how most time off is stored.
- A day off given to everyone at once (New Year's Day, say) is a "Time Off" assignment with neither a person nor a placeholder. These are not imported, since they are not anyone's in particular: set such days as public holidays in the planner. The preview lists them.
- Repeating assignments arrive already expanded, one assignment per repeat.
- Few projects carry a `harvest_id`, even in accounts that came from Harvest, so names are the reliable way to match.

## Limitations

- The plan is imported, not synchronised: Forecast stays the source until you switch it off. When an assignment changes in Forecast, its plannings are replaced and edits made to them in Kimai are lost; a planning deleted in Kimai comes back.
- Forecast milestones, project budgets and roles are not imported.
- A planning that clashes with one made in Kimai for the same person and project is not detected.

## Security

- **The token is kept only if you choose to.** Untick "Remember" and it is used for that one read and forgotten. Ticked, it is stored encrypted (libsodium secretbox, XSalsa20-Poly1305) under a key derived from Kimai's `APP_SECRET`, and only after Forecast has accepted it. A database dump alone does not reveal it; someone with both the database and the `APP_SECRET` can decrypt it, so clear it once the migration is done. Changing `APP_SECRET` makes a saved token unreadable, and you are simply asked for it again.
- It is never logged, shown or sent anywhere but `api.forecastapp.com`.
- With Symfony's debug toolbar enabled (`APP_ENV=dev`), the profiler records outgoing HTTP requests and submitted forms, token included, so run imports on production-mode instances or clear the profiler afterwards.

## Development

Tests run against an existing Kimai checkout, which supplies Kimai's classes and Symfony:

```bash
composer install -d tools/phpunit
KIMAI_DIR=/path/to/kimai tools/phpunit/vendor/bin/phpunit
```

`KIMAI_DIR` defaults to a `kimai` directory next to this one. The planner is loaded from `$KIMAI_DIR/var/plugins/PlannerBundle`.

## Licence

[AGPL-3.0-or-later](LICENSE), the same as Kimai and PlannerBundle.
