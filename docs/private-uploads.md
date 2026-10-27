# Private uploads

A file a person uploads that is not a picture the site shows stays on a private disk. Opening it goes through a route that checks who is asking. A disk the web server would serve is refused, so a wrong setting cannot publish the file.

> **Status:** built. Product pictures and blog covers stay public on purpose.

## What is private

| What | Where it is kept | Who can open it |
| --- | --- | --- |
| Support ticket files | `SUPPORT_ATTACHMENT_DISK` (`local` by default) | The person who opened the ticket, and staff who may see it. See [support-attachments.md](support-attachments.md). |
| Forum pictures and files | `FORUM_ATTACHMENT_DISK` (`local` by default) | Anyone who can see the post. See [forum-attachments.md](forum-attachments.md). |
| Product downloads | `COMMERCE_DOWNLOAD_DISK` (`local` by default) | A buyer, through a short-lived signed link. See [commerce.md](commerce.md#digital-goods). |
| A member's data export | the `local` disk | That member, while the export has not expired. |

The stored name is generated. The name the person chose is used only when the file is downloaded.

## What stays public

Product pictures and blog covers are the pictures the shop and the blog show. They are decoded and saved again as WebP before they are stored, on the public disk. See [blog-covers.md](blog-covers.md) and [commerce.md](commerce.md#product-pictures).

## A public disk is refused

Support files, forum files and product downloads refuse a disk whose visibility is `public`. The ticket, the reply or the product file is not saved, and nothing is written. The command that moves old support files off a public disk will not copy them onto one.

## Testing

`tests/Feature/Support/SupportAttachmentDownloadTest.php`: a public support disk opens no ticket and writes no file, and the move command fails without copying. `tests/Feature/Forum/ForumAttachmentTest.php`: a public forum disk adds no post and writes no file. `tests/Feature/Admin/Catalogue/ProductFileManagementTest.php`: a public download disk saves no product file.
