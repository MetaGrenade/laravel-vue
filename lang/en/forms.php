<?php

/*
 * What the site says when a member's form or action is refused for a reason that Laravel's own rules (validation.php) cannot put in
 * words: the answer to a form the member filled in, shown beside the field. Written by the server in the language of the request, so
 * this group is not sent to the browser. A message is a whole sentence with its :placeholders (docs/i18n.md).
 *
 * Not here: the messages of the admin area, which is English, and the ones that tell whoever runs the site to change a setting.
 */

return [
    // Settings → API keys.
    'api_keys' => [
        'name_required' => 'Give the key a name, so you can tell it from your others.',
        'scopes_required' => 'Choose at least one thing the key can do.',
        'scope_invalid' => 'That is not something a key can be limited to.',
        'expiry_required' => 'Choose how long the key should last.',
        'expiry_invalid' => 'Choose how long the key should last from the list.',
        'grace_required' => 'Choose how long the old key should go on working.',
        'grace_invalid' => 'Choose how long the old key should go on working from the list.',
        'limit_integer' => 'Enter a whole number of calls, or leave it empty for no limit.',
        'limit_min' => 'A limit must be at least 1 call. Leave it empty for no limit.',
        'limit_max' => 'A limit cannot be more than :max calls.',
        'too_many' => 'You already have :max API key you can use. Revoke one you no longer need, then make another.|You already have :max API keys you can use. Revoke one you no longer need, then make another.',
        'unverified' => 'Verify your email address first: API keys can only be made once it is verified.',
        'no_room_to_rotate' => 'You already have :max API key you can use, and rotating with a grace period needs room for one more while the old key winds down. Revoke one you no longer need, or rotate this key and stop the old one at once.|You already have :max API keys you can use, and rotating with a grace period needs room for one more while the old key winds down. Revoke one you no longer need, or rotate this key and stop the old one at once.',
        'rotate_dead' => 'This key no longer works, so there is nothing to replace. Make a new key instead.',
        'rotate_replaced' => 'This key has already been replaced, and is only working out its grace period.',
        'rotate_empty' => 'This key holds nothing the site recognises, so a copy of it would do nothing. Make a new key instead.',
        'limits_from_staff' => 'Staff set the limits on this key, so you cannot change them.',
    ],

    // A promotion code on a subscription (Settings → Billing, Pricing).
    'promotion_code' => [
        'not_valid' => "That code isn't valid, or it has expired.",
        'cannot_check' => "We couldn't check that code just now. Please try again in a moment, or remove it to carry on without it.",
        'refused_by_stripe' => "That code can't be used on this subscription. Nothing has been charged.",
        'not_for_subscriptions' => "That code can't be used on a subscription.",
        'wrong_currency' => "That code can't be used with this plan's currency.",
        'wrong_plan' => "That code doesn't apply to the :plan plan.",
    ],

    // Posting in the forum.
    'forum' => [
        'reply_empty' => 'Reply cannot be empty.',
        'post_empty' => 'Post content cannot be empty.',
        'thread_body_empty' => 'Please enter some content before publishing.',
        'title_empty' => 'The thread title cannot be empty.',
        'spam_rejected' => 'This looks like spam, so it was not posted. If you think that is a mistake, please contact a moderator.',
        'prefix_required' => 'Choose a prefix for this board.',
        'prefix_unavailable' => 'That prefix is not available on this board.',
        'solution_not_in_thread' => 'That reply is not in this thread.',
        'solution_is_opener' => 'The opening post cannot be the solution.',
        'follow_self' => 'You cannot follow yourself.',
        'follow_unavailable' => 'That member cannot be followed.',
        'ignore_self' => 'You cannot ignore yourself.',
        'ignore_moderator' => 'Moderators cannot be ignored.',
        'attachment_empty' => 'That file is empty.',
        'attachment_type' => 'Upload a JPEG, PNG, WebP or GIF picture, or a PDF, text file or zip.',
        'attachments_max' => 'A post can have :max file.|A post can have :max files.',
        'attachment_unsaved' => 'The file could not be saved.',
    ],

    // Direct messages between members.
    'direct_messages' => [
        'to_self' => 'You cannot message yourself.',
        'ignoring' => 'You are ignoring :name.',
        'unavailable' => 'That member cannot be messaged.',
        'empty' => 'A message cannot be empty.',
    ],

    // Comments on the blog, and the checks that keep spam out of them.
    'comments' => [
        'empty' => 'Comment cannot be empty.',
        'honeypot' => 'Spam check failed.',
        'captcha' => 'Invalid verification token.',
    ],

    // The support desk.
    'support' => [
        'picture_unsaved' => 'That picture could not be saved. Try again.',
        'already_open' => 'This ticket is already open.',
        'rate_after_closed' => 'You can only rate a ticket after it has been closed.',
        'already_rated' => 'This ticket has already been rated.',
    ],

    // Settings → Security and Notifications.
    'two_factor' => [
        'not_started' => 'Two-factor authentication has not been initiated.',
        'code_invalid' => 'The provided authentication code is invalid.',
        'codes_failed' => 'Recovery codes could not be generated. Please try again.',
        'enable_first' => 'Enable multi-factor authentication before generating recovery codes.',
    ],
    'notification_settings' => [
        'invalid' => 'Invalid notification preferences payload.',
    ],

    // The shop.
    'reviews' => [
        'rating_required' => 'Choose a rating from 1 to 5 stars.',
        'rating_between' => 'Choose a rating from 1 to 5 stars.',
        'body_required' => 'Tell others what you thought of it.',
        'body_min' => 'Write a little more: at least :min characters.',
    ],
    'checkout' => [
        'username_required' => 'Enter your :label.',
        'username_too_long' => 'That :label is too long.',
        'username_characters' => 'That :label has characters it cannot have.',
        'address_missing' => 'That saved address could not be found.',
        'country_not_served' => "Sorry, we can't ship to that country.",
    ],

    // A picture that was uploaded (forum attachments, support tickets).
    'images' => [
        'unreadable' => 'That file could not be read as a picture. It may be damaged.',
        'type' => 'Upload a JPEG, PNG, WebP or GIF picture.',
        'no_size' => 'That picture has no size.',
        'too_large' => 'That picture is :size megapixels; the most allowed is :max. Make it smaller and try again.',
        'resize_failed' => 'That picture could not be resized.',
        'convert_failed' => 'That picture could not be converted.',
    ],
];
