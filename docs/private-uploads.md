# Private uploads

A file a person uploads that is not a picture the site shows stays on a private disk. Opening it goes through a route that checks who is asking. A disk the web server would serve is refused, so a wrong setting cannot publish the file.

> **Status:** built. Product pictures and blog covers stay public on purpose.

## What is private

| What | Where it is kept | Who can open it |
| --- | --- | --- |
| Support ticket files | `SUPPORT_ATTACHMENT_DISK` (`local` by default) | The person who opened the ticket, and staff who may see it. See [support-attachments.md](support-attachments.md). |
| Forum pictures and files | `FORUM_ATTACHMENT_DISK` (`local` by default) | Anyone who can see the post. See [forum-attachments.md](forum-attachments.md). |
| Product downloads | `COMMERCE_DOWNLOAD_DISK` (`local` by default) | A buyer, through a short-lived signed link. See [commerce.md](commerce.md#digital-goods). |
| A member's data export | the `local` disk, in `exports/` | That member, for the 30 minutes after it is ready. The file is deleted after that ([below](#data-export-files)). |

The stored name is generated. The name the person chose is used only when the file is downloaded.

## Data export files

The zip of a member's data export holds everything the site keeps about them, and the member is told the link works for 30 minutes (`DataExport::DOWNLOAD_TTL_MINUTES`). **The file is deleted when those 30 minutes are over.** The scheduler queues `PruneDataExports` every 15 minutes (`bootstrap/app.php`) and a worker runs it ([the queue](redis.md#the-queue)), so a file is gone within 45 minutes of the export being ready. The row stays, with no file, as the record that the member asked.

The job makes two passes over `exports/` on the `local` disk:

1. An export whose 30 minutes are over, and that still names a file, loses the file.
2. A file that no export names and that is more than an hour old (two windows) is deleted: the zip of a member whose account was deleted (the account takes its export rows with it, and nothing took the file), or one that a job wrote and then could not record. A younger file may be one a job is still writing, so it is left. A file whose name starts with a dot (`.gitignore`) is not touched, and nothing outside `exports/` is.

Until this job the file went only if somebody opened the member's privacy page or the download link after the 30 minutes were over. A member who downloaded the zip in time and never came back left it on the disk for good, and so did a member who deleted their account. **On a site that ran an earlier version, the first run deletes all of those:** every file in `exports/` that no export names and that is over an hour old, and the file of every export whose time is over.

**Staff can say which file an export is** (Admin → Trust and safety, marking an export finished). The path has to be a file directly in `exports/`, such as `exports/user-4-20261116_120000.zip`: the member downloads whatever it names and the job deletes it. It accepted any path on the private disk, so a member of staff could have a support attachment, a forum attachment or a product download handed to them as their own "export", and have it deleted afterwards.

**A row that an earlier version saved with another path is still in the database of a site that upgraded**, so the rule is also asked of the path that is stored, wherever the site acts on it. A path that is not a file directly in `exports/` is not an export's file: the privacy page does not offer it, the download link answers 404 and does not serve it, and when the export's 30 minutes are over the row forgets the path and **the file it named is not deleted** (it is a support attachment or a product download, not the job's to delete). Such a row is not cleaned up in any other way; look for them with `select id, file_path from data_exports where file_path is not null and file_path not like 'exports/%'` if you want to know whether a member of staff ever named one.

## What stays public

Product pictures and blog covers are the pictures the shop and the blog show. They are decoded and saved again as WebP before they are stored, on the public disk. See [blog-covers.md](blog-covers.md) and [commerce.md](commerce.md#product-pictures).

## A public disk is refused

Support files, forum files and product downloads refuse a disk whose visibility is `public`. The ticket, the reply or the product file is not saved, and nothing is written. The command that moves old support files off a public disk will not copy them onto one.

## Testing

`tests/Feature/Support/SupportAttachmentDownloadTest.php`: a public support disk opens no ticket and writes no file, and the move command fails without copying. `tests/Feature/Forum/ForumAttachmentTest.php`: a public forum disk adds no post and writes no file. `tests/Feature/Admin/Catalogue/ProductFileManagementTest.php`: a public download disk saves no product file. `tests/Feature/Settings/PruneDataExportsTest.php`: an export past its window loses its file and keeps its row, one inside it keeps both, the job and `DataExport::hasExpired()` agree to the second, more rows than one chunk are all cleared, a file no export names goes at two windows and not before (the zip of a deleted member among them), a file an open export names is never taken, a dot file and everything outside `exports/` are left, an expired row saved with the path of another file (a support attachment, a forum attachment, a product download, a way out of `exports/`) forgets it and the file is not deleted, by the job or by the privacy page, nor served by the download link, and the schedule is every 15 minutes. `tests/Feature/Admin/DataExportFilePathTest.php`: a path outside `exports/` (another folder, a way out of it, a hidden file, a line break after a good name) is refused, one inside is accepted.
