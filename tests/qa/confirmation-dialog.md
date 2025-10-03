# Confirmation Dialog QA Checklist

Use this checklist to manually verify that the reusable confirmation dialog works across pages and is accessible to keyboard users.

## Setup
- Sign in as a user with permission to delete comments, threads, and admin resources.
- Navigate to a page that renders the confirmation dialog (e.g. blog comments, forum thread view, ACP list pages).

## Keyboard Interaction
1. Focus the delete trigger button using `Tab` or by navigating with the keyboard.
2. Press `Enter` or `Space` to open the dialog.
3. Confirm that focus automatically moves to the **Cancel** button.
4. Use `Tab` and `Shift+Tab` to cycle between the Cancel and Delete buttons—focus should remain trapped inside the dialog.
5. Press `Escape` to close the dialog and ensure focus returns to the original trigger button.
6. Reopen the dialog and press `Enter` while the Delete button is focused to execute the destructive action.

## Screen Reader / Announcement
- Verify that the dialog is announced as an alert dialog with the provided title and description when it opens.

## Disabled State
- While a deletion request is in progress, confirm that the Delete button is disabled to prevent duplicate submissions.

## Regression Checks
- Canceling the dialog should not perform any deletion.
- After confirming deletion, the affected item should be removed from the list and pagination totals updated where applicable.
