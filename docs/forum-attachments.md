# Pictures and files

A post can carry a picture or a file. It is shown with the post, and it can be opened only while the post itself can be seen.

> **Status:** built: up to five files on a new thread, a reply or an edited post; a picture is drawn again and shown; a PDF, a text file or a zip is downloaded. **Not built:** see [below](#not-built).

## Using it

On a new thread, a reply, or when editing a post, choose **Pictures and files**. A picture (JPEG, PNG, GIF or WebP) is decoded and saved again as a WebP, so the file that is shown is not the file that was uploaded. A PDF, a plain text file or a zip is kept as it is and offered as a download. Anything else is refused, including a picture that is really a document, and a file that is empty or larger than 4 MB.

The files sit under the post. While a reply is held for review, only its author and the moderators can open them. A thread that is held is not published, so only a moderator can open its files until it is approved. Deleting the post, deleting the thread, or deleting the account removes the files. Taking down a member's replies removes the files on those replies; a thread of theirs is unpublished, and its files stay until the thread is deleted.

Editing a post can add files and can remove one that is already there.

## Not built

- **A picture placed inside the sentence.** It is shown with the post.
- **The API**, and the list in a data export.
- **RSS.**

## Testing

`tests/Feature/Forum/ForumAttachmentTest.php`: a picture is shown and served as WebP, a PDF is downloaded, a document that claims to be a picture is refused, too many files and a file over the size are refused, an edit that would exceed five files does not publish, a failed replacement leaves the existing file, a held reply's file is not public, a held thread's file is only for a moderator, deleting the post removes the file, editing can remove one, taking down a member's replies removes theirs, and deleting an account removes files on replies in threads they started.
