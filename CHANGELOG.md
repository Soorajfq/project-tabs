# Changelog

## 1.2
- PIN lock: a padlock on every section. Locked sections show as masked until you enter your 6-digit PIN; the text and files are not even sent to the browser while locked. They re-lock after 5 minutes, when you leave the app for over a minute, or from "Lock everything now" in Settings.
- Create, change and reset the PIN in Settings. Resetting a forgotten PIN needs your account password plus a 2FA or recovery code, and never touches your data.
- Five wrong PINs lock further attempts for 15 minutes.
- Optional: lock new "Credentials" sections automatically.
- Downloading a backup asks for the PIN when any section is locked, so a backup cannot be used to bypass the lock.
- Updating from 1.1 is safe: it only adds a `locked` column and stores the PIN hash in the settings table, both created automatically.
- Note: the lock hides data, it does not encrypt it. Backups hold the text as plain text.

## 1.1
- Project groups: coloured, collapsible headings in the project list. Create, rename, recolour and delete groups. Deleting a group never deletes projects, they just become ungrouped.
- Move projects by dragging onto a group, or with the ⋯ menu (also "New group…").
- Search box above the project list.
- Backups and exports now include groups.
- Updating from 1.0 is safe: the upgrade only adds a `project_groups` table and a `group_id` column, created automatically on first load. Nothing is changed or removed.

## 1.0
- First public release.
