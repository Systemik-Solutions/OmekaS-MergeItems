# Merge Items

Merge Items provides a Omeka S admin workflow for merging item records. Authorized users can compare selected items, choose the record to keep, append selected metadata and media to it, and remove the
duplicates.

## Requirements

- Omeka S 4.x, version 4.1.1 or later

## Installation

The module directory must be named exactly `MergeItems`.

### Download

1. Download the module from the [GitHub repository](https://github.com/Systemik-Solutions/OmekaS-MergeItems).
2. Extract the downloaded archive into the `modules` directory of your Omeka S installation.
3. Rename the extracted directory to `MergeItems`.


### Git

Alternatively, clone the repository directly into the correctly named directory:

```sh
cd /path/to/omeka-s/modules
git clone https://github.com/Systemik-Solutions/OmekaS-MergeItems.git MergeItems
```

After installing the files, sign in to the Omeka S admin interface, open
**Modules**, and click **Install** for **Merge Items**.

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
- References from any surviving resource—including items, item sets, media,
  and value annotations—to a duplicate are changed to point to the master
  record before the duplicate is deleted. These referrers are listed in the
  References panel before the merge.
- The References panel reports sources that cannot be viewed or updated with
  the current account. It also reports reference-loading errors instead of
  presenting them as an empty reference list. A merge cannot proceed unless
  every surviving referencing resource can be updated.
- Every non-master item is permanently deleted when the merge is committed.
- Media that is not selected is deleted along with its duplicate item.

Unchecked metadata is not copied to the master. It is permanently removed when
the duplicate item is deleted.

## Important

Committing a merge is destructive. Review the master record and checkbox
selections carefully before clicking **Commit changes**. Deleted duplicate
items and unchecked media cannot be restored by this module.
