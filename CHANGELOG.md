# Changelog

## 1.1
- Project groups: coloured, collapsible headings in the project list. Create, rename, recolour and delete groups. Deleting a group never deletes projects, they just become ungrouped.
- Move projects by dragging onto a group, or with the ⋯ menu (also "New group…").
- Search box above the project list.
- Backups and exports now include groups.
- Updating from 1.0 is safe: the upgrade only adds a `project_groups` table and a `group_id` column, created automatically on first load. Nothing is changed or removed.

## 1.0
- First public release.
