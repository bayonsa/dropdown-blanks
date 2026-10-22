# SERU Dropdown Blanks

Dropdown fill-in-the-blank questions for LearnDash on serutests.co.uk.

The old "Jumbled Sentence" question type came from a third-party add-on that stopped working
(its licence file was encoded with an unauthorised ionCube encoder and the server's loader now rejects it).
This plugin brings the same experience back using only LearnDash core:

1. **Convert** – each old question becomes a LearnDash *Fill in the blank* (cloze) question built from the
   data the old add-on left in the database (`_ld_advanced_jumbled_question`, `_ld_advanced_jumbled_options`).
   Correct answers and per-blank points are kept. A full backup is stored per question (`_sddb_backup`).
2. **Display** – a small script swaps every blank's text box for a dropdown with the original choices.
   The hidden text box still holds the answer, so LearnDash grades, stores and reports it as normal.
3. **Revert** – any question (or all) can be restored exactly from its backup.

## Use
Tools → **SERU Dropdown Blanks**: preview each question, convert one to check it, then *Convert all valid*.
Always take a database backup (BackWPup) first.

## Updates
Installed from github.com/bayonsa/dropdown-blanks with [Git Updater](https://git-updater.com/). New versions pushed to `main` (with a bumped
`Version:` header) appear as normal plugin updates in WordPress.

## Development
- `php tests/transform-test.php` – conversion logic tests
- `node tests/js-test.js` (Playwright) – dropdown behaviour against mock LearnDash markup
