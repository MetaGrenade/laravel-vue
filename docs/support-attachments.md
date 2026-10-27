# Support attachments

A ticket can carry a file. A picture is drawn again before it is stored. Anything else on the allow-list is kept as it arrived, on a private disk, and is downloaded only by someone who may see the ticket.

> **Status:** built: pictures are decoded and saved again as WebP. **Not built:** see [below](#not-built).

## Using it

On a new ticket or a reply, choose up to five files, each at most 10 MB. A JPEG, PNG, GIF or WebP is decoded and saved again as WebP, at its own size, so the file that is stored is not the file that was uploaded. A note in the picture (a camera location, a comment) does not survive. A picture with more pixels than the shop allows is refused before it is decoded, and the ticket or reply is not saved. A PDF, a plain text or CSV file, JSON, a zip or a spreadsheet is kept as it arrived. Anything else is refused.

The stored name is generated. The download uses the name the person chose, with `.webp` for a picture, and is always sent as a download. A type that is not on the list of types the browser may render is sent as an opaque file.

## Not built

- **Pictures already on a ticket.** They are not drawn again on their own.
- **A virus scan.**

## Testing

`tests/Feature/Support/SupportAttachmentImageTest.php`: a picture is stored as WebP and a note in the file does not survive; a picture with too many pixels opens no ticket and adds no reply; a PDF is kept as the uploaded file. Downloads and the move off the public disk are covered by `tests/Feature/Support/SupportAttachmentDownloadTest.php`.
