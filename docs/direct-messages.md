# Direct messages

A member can write to another member. The conversation is private between the two of them.

> **Status:** built: a message from a profile, the conversation, an inbox, and a notice when a message arrives. **Not built:** see [below](#not-built).

## Using it

Signed in, open someone's profile and choose **Message**. Your own profile links to **Messages**. The account menu does too.

The conversation lists what you have written to each other, oldest first, twenty at a time. Opening it lands on the latest page. A message is plain text: markup is taken out, and a blank message is refused. Writing again uses the same conversation.

You cannot message yourself. You cannot message a banned member, and a banned member cannot start a new conversation. One you already have stays in your inbox and can be read, but not continued.

While you ignore someone, neither of you can send the other a message. The conversation you already have stays. You are told "You are ignoring {name}". They are told only that you cannot be messaged. A moderator, an editor, or an administrator is not ignored, so a message to them still arrives, including when the ignore was saved before the role. If the role is taken away, the earlier choice blocks messages again. See [forum-ignores.md](forum-ignores.md).

You are told, in the site and by email when you allow it, when someone messages you. **Settings → Notifications** calls that **Direct messages**. The link opens the conversation. The email can be a daily digest ([email-digests.md](email-digests.md)).

Deleting either account drops the conversation and its messages.

## Not built

- **A conversation of more than two people.**
- **Pictures and files.**
- **Editing or deleting one message.**
- **Automatic moderation** of the text.
- **The messages in a data export**, and an API for them.
- **A moderator's inbox of everyone's messages.** Viewing the site as that member can open the page, and it changes nothing.

## Testing

`tests/Feature/Members/DirectMessageTest.php`: two members can write, a tagged message is stored as text, the recipient is told and the sender is not, a reply reuses the conversation, an ignore stops both directions while a moderator can still be messaged, a long conversation opens on its last page, you cannot message yourself or a banned member, an existing conversation with someone later banned can be read but not continued, deleting an account removes it, and the pages stay when the forum is off.
