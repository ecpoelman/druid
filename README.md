# druid

druid is a command line shell and Unix scripting interface for **upgrading Drupal** core and contrib.
It works like Drush (`@alias`, `--root`, `--uri`, `-y`, `--simulate`), uses Drush when the project has it,
and keeps track of every local Drupal site that needs security or compatibility updates.

- **Scan**: security advisories (composer audit + `drush pm:security`), unsupported projects, minor and major
  updates, and drupal.org release data, for one site or all of them.
- **Report**: coloured tables, or JSON / CSV / Markdown / HTML for every tracked site.
- **Plan**: numbered steps with the exact Composer commands, always targeting the safest release
  (the lowest version that clears every advisory, in the same major).
- **Upgrade**: a whole site, core only, or individual modules. Each step is snapshotted and verified. If it fails,
  druid tries another strategy, then defers the step and carries on. It does not abort the run.
- **Major upgrades** (e.g. 10 → 11): upgrade_status readiness check, compatible contrib releases first,
  the composer-drupal-lenient plugin for modules that haven't declared support yet, drupal-rector for custom code.

Requires PHP 8.2+ and Composer 2 (2.7+ recommended for `--minimal-changes`).

## Install

druid installs the same way as Drush.

### Per project (recommended)

```bash
composer require --dev druid/druid
vendor/bin/druid status
```

### Global launcher

```bash
composer global require druid/druid
druid status
```

Make sure Composer's global bin directory is on your `PATH` (`composer global config bin-dir --absolute`).
Like the Drush launcher, a global `druid` hands off to the project's own `vendor/bin/druid` when you run it inside a
project that has one. Set `DRUID_NO_HANDOFF=1` to always use the global copy.

### From a checkout (before the package is published)

```bash
git clone <repo> ~/druid && cd ~/druid && composer install
ln -s ~/druid/bin/druid ~/.local/bin/druid     # or add ~/druid/bin to PATH
```

Or add the checkout as a path repository in a project:

```json
"repositories": [{"type": "path", "url": "../druid"}]
```

then `composer require --dev druid/druid:@dev`.

### PHAR

```bash
box compile        # https://github.com/box-project/box, uses box.json
./druid.phar status
```

## Quick start

```bash
druid site:discover          # find sites in ~/Sites, Herd, DDEV and Drush alias files
druid sites                  # tracked sites and their last known status
druid report --all           # security / update overview of every site

cd ~/Sites/mysite
druid scan                   # what needs updating, colour-coded by severity
druid plan                   # the steps and exact commands (security scope)
druid upgrade                # apply them
```

Target any tracked site from anywhere with `@name`, as in Drush:

```bash
druid @mysite plan --minor
druid @mysite upgrade -y
```

## Commands

| Command | Aliases | What it does |
|---|---|---|
| `status` | `st` | Versions, tooling, environment (Herd/DDEV/Lando), Git state, last scan |
| `scan` | `sec` | Advisories, unsupported projects, minor/major updates (`--all`, `--format=json`) |
| `report` | `rp` | One or all sites as table, json, csv, md or html (`--output=file`) |
| `plan` | `pl` | Outline steps and commands without changing anything |
| `upgrade` | `up` | Upgrade the whole site: security by default, `--minor`, `--major` |
| `upgrade:core [version]` | `up:core` | Core only: security/latest patch, `--minor`, an exact version, or `11` |
| `upgrade:module <names>...` | `up:m` | Individual modules, themes or libraries (`--to`, `--major`, `--latest`) |
| `upgrade:major [target]` | `up:major` | Move to the next Drupal major (`--rector` applies drupal-rector) |
| `upgrade:check` | `readiness` | Drupal-major readiness: every module's support, PHP, Drush, upgrade_status |
| `rollback` | `rb` | Restore Composer files (and with `--with-db` the database) from a snapshot |
| `site:add` / `site:remove` / `site:list` / `site:discover` | `sa`, `sites`, `discover` | Manage tracked sites |
| `init` | | Write a commented `druid.yml` into the project and track it |
| `doctor` | | Check PHP, Composer, Drush, DDEV/Lando/Herd, network and storage |

Global options: `@name` / `--site`, `-r|--root`, `-l|--uri`, `-y|--yes`, `--simulate`, `-v` (full Composer output).

### Scopes

| Flag | Includes |
|---|---|
| *(default)* `--security` | Releases that fix advisories, choosing the lowest safe one |
| `--minor` | Security, plus the latest minor/patch of every direct dependency within its current major |
| `--major` | Minor, plus the next Drupal core major and the contrib moves it needs |
| `--latest` | Prefer the newest release instead of the minimum safe one |

### Exit codes

`0` everything done · `2` finished, but some steps were deferred · `1` failed (nothing could run). Exit codes make druid easy to script, e.g. in cron or CI:

```bash
druid upgrade --all -y || echo "some sites need attention"
```

## How an upgrade runs

1. **Preflight**: checks that Composer works, warns about uncommitted composer files, checks that Drush can
   bootstrap Drupal, saves a snapshot of `composer.json`/`composer.lock` (and patches files) under `~/.druid/snapshots`,
   and takes a database dump with `drush sql:dump` when possible.
2. **Steps, core first**: Drupal core packages always move together. Contrib stays locked until core
   succeeds. A step whose package is already at the target (often moved by an earlier step) is skipped.
3. **The strategy ladder**, tried for every step until one works:

   | Strategy | Composer approach |
   |---|---|
   | `isolated` | `update pkg:VER --with-dependencies --minimal-changes`; composer.json is only touched when its constraint must change |
   | `require_bump` | raise the constraint explicitly, keeping its style (`^`, `~` or exact pin) |
   | `blockers` | update together with the packages Composer and `why-not` name as blockers |
   | `all_dependencies` | `--with-all-dependencies` |
   | `no_audit_blocking` | retry with `COMPOSER_NO_SECURITY_BLOCKING=1` when audit blocking is in the way |
   | `core_first` | update core within its major first when a module needs a newer core |
   | `step_down` | other safe releases near the target (closest first) |
   | `lenient` | major upgrades: allow modules through `mglaman/composer-drupal-lenient` |

   Every failed attempt is rolled back (and vendor re-synced) before the next one. Some failures are fixed
   automatically: network retries, `COMPOSER_MEMORY_LIMIT=-1`, read-only `sites/default` during scaffolding, and
   trusted Composer plugins that a new release pulls in (such as `symfony/runtime` in Drupal 11). When a failure
   reveals something new, such as audit blocking, the ladder runs a second time with that knowledge.
4. **Verification** after each step: `composer validate` and `drush cache:rebuild` (with `updatedb` when the
   new code needs it). A step that breaks the site is rolled back.
5. **Defer, don't fail**: a step that still fails is recorded with a diagnosis, hints and the manual
   command, and the run continues with the next step.
6. **After the run**: `drush updatedb` and `drush cache:rebuild` (configurable, plus optional `config:export`), a history
   entry in `~/.druid/state`, and a rescan to confirm what's left.

## Major upgrades

```bash
druid upgrade:check                          # compatibility of every module, PHP, Drush
druid upgrade:check --install-upgrade-status # also scan code for deprecations (upgrade_status)
druid plan --major                           # review the full path
druid upgrade:major --rector                 # do it, applying drupal-rector to custom modules
```

druid moves core to the latest minor of the current major first. It then installs contrib releases that support
both majors, adds modules that only support the new major to the core step, puts modules with no declared
support on the lenient allow-list, bumps Drush to 13 for Drupal 11, and finally moves all core packages together.

Along the way it:

- reads each release's real `drupal/core` requirement from packages.drupal.org (the release feed is sometimes
  looser), and uses a pre-release only when it is the sole release that supports the new major;
- adds the contrib replacement of enabled core modules the new major removes (`tour`, `forum`, `book`, … in
  Drupal 11; `color`, `rdf`, `seven`, … in Drupal 10) to the core step;
- stops before the core step when PHP is pinned too low (`config.platform.php`, `pantheon.yml`,
  `.ddev/config.yaml`) and prints the exact fix;
- releases transitive packages that hold a dependency back (e.g. `chi-teck/drupal-code-generator` pinning
  `symfony/console` 6) when Composer reports "found … but these were not loaded";
- defers steps that need the new core and retries them once core has moved;
- adds the new major to `core_version_requirement` in custom modules and themes, so Drupal doesn't mark them
  incompatible;
- checks custom code with phpstan-drupal's deprecation rules when upgrade_status isn't installed (both ship
  with `drupal/core-dev`).

After a major upgrade, a normal `druid upgrade` repairs leftovers: it reinstalls removed core modules that are
still enabled from contrib, and fixes custom `.info.yml` files. `druid upgrade --rector` applies drupal-rector
at any time.

## Configuration

Settings are merged in this order (later wins): built-in defaults → `~/.druid/druid.yml` → `<project>/drush/druid.yml`
→ `<project>/druid.yml`. `druid init` writes a commented example.

```yaml
environment: auto          # auto | native | ddev | lando
discovery:
  paths: [~/Sites]
upgrade:
  backup: true
  verify: true
  strategies: [isolated, require_bump, blockers, all_dependencies, no_audit_blocking, core_first, step_down, lenient]
  post_update: [updatedb, cache:rebuild]
  export_config: false
  trusted_plugins: [symfony/runtime, php-http/discovery, cweagans/composer-patches]
packages:
  exclude: [drupal/patched_module]
  pin:
    drupal/core: "~10.6.0"
```

Storage lives in `~/.druid` (override with `DRUID_HOME`): `sites.yml`, `state/`, `snapshots/`, `cache/`.

DDEV and Lando projects are detected automatically: Composer and Drush then run inside the container
(`ddev exec`, `lando composer`), so PHP versions and databases match the site.

## Development

```bash
composer install
composer test          # PHPUnit
```
