# Survey Pipeline

Turns selected form submissions into a visual order pipeline —
**New → Quoted → Scheduled → In Progress → Delivered**.

Built for CMS Surveyors on top of the **Forms** plugin. Connect any
published form (Sailboat Survey Order, Powerboat Survey Order, or
any future form) from the settings page — only connected forms feed
the pipeline. Every other form keeps working exactly as before.

## What it does

- Adds **Survey Pipeline** and **Pipeline Settings** to the admin sidebar
- Listens for the `forms_submitted` hook fired by Forms after every
  submission; if the form is connected, a pipeline order is created
  automatically with key fields (vessel, client, locale, LOA) pulled
  out of the submission using a field map you configure
- Pipeline board: tabbed by stage, click any order to open a detail
  drawer with a voyage-progress rail, stage mover, note timeline, and
  a link back to the raw Forms submission
- Dashboard widget showing live stage counts
- Emails the admin on every new order (configurable)

## Requirements

- Slate core `>=1.0.0`
- Works best with the **Forms** plugin installed and active. If Forms
  isn't active, the settings page will show no available forms to
  connect, but the plugin itself still installs and activates fine.

## Install

1. Plugins → Upload a plugin → select `survey-pipeline-v1.0.0.zip`
2. Activate
3. Go to **Pipeline Settings**, connect "Sailboat Survey Order" and
   "Powerboat Survey Order" (or any other form), map their fields
4. Submissions to connected forms now appear in **Survey Pipeline**

## Field mapping

Slate's form builder lets each form use its own field names (a
sailboat form might call the vessel field `boat_make_model`, a
powerboat form might call it `vessel_name`). The settings page lets
you tell the pipeline which field on *that specific form* holds each
of: vessel name, client name, client email, client phone, survey
locale, and LOA. Unmapped values just show as "—" in the pipeline —
nothing breaks.

## Hooks fired

None yet exposed for other plugins to listen to in v1.0. The public
`SurveyPipelineAPI` class is the integration point — see the
class-level docblock in `SurveyPipelineAPI.php` for the full method
list (`stageCounts()`, `ordersByStage()`... `getOrder()`,
`moveStage()`, `addNote()`).

## Permissions

| Key | Grants |
|---|---|
| `surveypipeline.view` | See the pipeline board and dashboard widget |
| `surveypipeline.manage` | Move stages, add notes, edit order fields |
| `surveypipeline.admin` | Connect/disconnect forms, edit field maps, general settings |

Manager role gets `view` + `manage` by default on install. `admin`
is super-admin only by default — grant it to other roles from
Roles if needed.

## Out of scope (v1.0)

No Stripe/deposit collection, no PDF report generation, no
drag-and-drop board (tabs only), no client-facing portal. See
`REQUIREMENTS.md` (project docs, not shipped in the plugin) for the
full roadmap.
