# Readable dates — 0.13.1

## Behavior

Service timestamps use WordPress's date format, time format, current locale and
site timezone. The shared formatter covers source reindexing dates in Overview
and Index, document update dates in the Index list/detail view, conversation
creation dates and individual message times. It does not change API values,
stored timestamps or dates written within user content.

Conversation date filters now use the displayed local calendar day. The history
screen identifies the site timezone and no longer labels local dates as UTC.
Daylight-saving rules and fixed fractional offsets follow WordPress timezone
settings. Missing or malformed displayed dates use a dash, without guessing
the current time or an absent timezone.

## Verification

- The full isolated WordPress suite passed **393 checks**, including **32 date
  checks** and all existing history, indexing, upload and localization checks.
- Date checks cover fractional seconds, explicit positive/negative offsets,
  spring and autumn daylight-saving transitions, midnight boundaries, fixed
  half-hour offsets, the Unix epoch, custom 12-hour/date formats and invalid data.
- Rendered checks cover Overview, Index, document detail, conversation list and
  messages. Filter tests include a UTC timestamp that belongs to the next local
  day and exclusion of unknown dates from an active date range.
- PHP lint, gettext format validation and `git diff --check` passed.
- All seven packaging checks passed. The 0.13.1 installable ZIP contains the
  shared date formatter and updated English/Russian catalogs.

## Browser verification

The real WordPress integration at `https://local.dzenchat.com:8869/` displayed
readable English dates in conversations, messages, Overview and Index, using
the site's existing UTC timezone and WordPress format. For example, the message
time became `September 11, 2026 1:46 am` instead of a timestamp with microseconds.
The service connection and project data were not modified.

The isolated site at `http://127.0.0.1:8870/` temporarily used Russian,
`Europe/Sofia`, date format `j F Y` and time format `H:i`. Index, document detail
and conversation history displayed `11 сентября 2026 04:00` for the fixture's
01:00 UTC timestamp. The history timezone hint was localized. At the supplied
843-pixel viewport width, the page had no horizontal overflow or visible ISO
timestamp metadata.

The original fixture language, timezone, date/time formats and widget selection
were restored. No production deployment or main Dzen Chat changes are included.
