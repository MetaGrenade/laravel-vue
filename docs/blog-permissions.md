# Blog permissions

The blog screens in the admin area follow named permissions.

> **Status:** built: posts, comments, tags and categories. **Not built:** see [below](#not-built).

## Using it

The permissions are `blogs.acp.view`, `create`, `edit`, `delete` and `publish`. An administrator has them. Anyone else needs the permission itself.

**View** opens the post list and the comment queue. **Create** starts a post and a tag or category. **Edit** changes a post, turns its comments on or off, edits a comment or a report, and edits a tag or category. **Delete** removes a post, a comment, a tag or a category. **Publish** publishes a post, returns it to draft, or archives it, including the bulk action.

The tag list and the category list are also the pickers on a post, so view, create or edit may load them. After a post is saved or deleted, someone who can open the list goes there. Someone who cannot goes back to the form they were using.

A member can still edit or delete a comment they wrote.

## Not built

- **The rest of the admin area.** Forum, support, members and the other screens are a later pass of the same review.
- **A change to who an administrator is.** An administrator still has every permission.

## Testing

`tests/Feature/Admin/BlogAuthorizationTest.php`: an editor with no blog permission cannot open or change a post, each permission allows only its own action, the tag list loads for someone who can create a post, and a member can still change a comment they wrote.
