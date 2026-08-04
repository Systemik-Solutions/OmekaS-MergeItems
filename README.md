# Merge Items

Merge Items provides a Omeka S admin workflow for merging item records. Authorized users can compare selected items, choose the record to keep, append selected metadata and media to it, and remove the
duplicates.

## Requirements

- Omeka S 4.x, version 4.1.1 or later

## Workflow

1. Open the Items browse page at `/admin/item` and select at least two items.
2. Choose **Merge selected** from the batch actions and click **Go**.
3. Compare the selected records in the horizontally draggable carousel and select one as the master record. 
4. Click **Merge items** to open the confirmation page.
5. Review the master record and choose which metadata and media from each
   duplicate should be added to it.
6. Click **Commit changes** to complete the merge.

## Merge rules

- Only properties that already contain values on the master record are offered
  for merging.
- All available property and media groups are selected by default except the
  master's configured title property 
- Selecting a property appends all values for that property to the master.
- Selecting a media group moves all media from that duplicate to the master.
- The master record keeps its resource template, resource class, visibility,
  item sets, site assignments, and other item-level settings.
- References from surviving items to a duplicate are changed to point to the
  master record before the duplicate is deleted.
- Every non-master item is permanently deleted when the merge is committed.
- Media that is not selected is deleted along with its duplicate item.

Unchecked metadata is not copied to the master. It is permanently removed when
the duplicate item is deleted.

## Important

Committing a merge is destructive. Review the master record and checkbox
selections carefully before clicking **Commit changes**. Deleted duplicate
items and unchecked media cannot be restored by this module.
