<?php

/*
 * The words of what the site sends a person: the emails and the notices in the site and the browser alerts that go with them
 * (docs/i18n.md, "Emails"). A notification is made in the language of the person it is for, whatever language the request that caused
 * it was in (`User::preferredLocale()`), so these are read by the server only and are not one of the groups sent to the browser.
 *
 * Whole sentences with their values as :placeholders, so that a language can put the values where its own grammar wants them, and
 * `|` for a singular and a plural. Text somebody typed (a reason, a message) is not here: it goes in as it was written.
 */

return [
    'greeting' => 'Hi :name!',
    'greeting_anonymous' => 'Hi there!',

    // A moderator's contact line, in the emails about a ban and about a warning.
    'moderation_contact' => 'If you think this was a mistake, contact :contact.',

    'account_banned' => [
        'subject_suspended' => 'Your account has been suspended',
        'subject_banned' => 'Your account has been banned',
        'suspended' => 'A moderator has suspended your account until :date. You cannot sign in until then.',
        'banned' => 'A moderator has banned your account. You cannot sign in.',
        'reason' => 'The reason given:',
    ],

    'ban_lifted' => [
        'subject_ran_out' => 'Your suspension has ended',
        'subject_lifted' => 'Your ban has been lifted',
        'ran_out' => 'The suspension on your account has ended. You can sign in again.',
        'lifted' => 'A moderator has lifted the ban on your account. You can sign in again.',
        'rules' => 'Please keep to the rules: a further breach may lead to another ban.',
        'action' => 'Sign in',
    ],

    'warning_issued' => [
        'subject' => 'A moderator has warned you',
        'about' => 'A moderator has given you a warning about something you posted: :about',
        'without_about' => 'A moderator has given you a warning about something you posted.',
        'nothing_else' => 'Nothing else has changed: your account is as it was. But please read the rules again, as a further breach may lead to a ban.',
        'action' => 'See your warnings',
    ],

    'api_key_created' => [
        'subject_rotation' => 'An API key on your account was rotated',
        'subject_staff' => 'Staff added an API key to your account',
        'subject_created' => 'A new API key was made on your account',
        'rotation' => 'Your API key :old was rotated: a new key called :name was made to replace it.',
        'staff' => 'A member of staff made an API key called :name for your account.',
        'sign_in' => 'An API key called :name was made by signing in over the API with your email address and password.',
        'settings' => 'An API key called :name was made in your account settings.',
        'old_until' => 'The old key keeps working until :date, so whatever uses it has time to be given the new one.',
        'old_stopped' => 'The old key stopped working at once.',
        'address' => 'It was made from the address :address.',
        'full_access' => 'It has full access to your account.',
        'no_access' => 'It cannot do anything.',
        'scopes' => 'It can: :scopes.',
        'expires' => 'It expires on :date.',
        'does_not_expire' => 'It does not expire.',
        'if_not_you' => 'If this was you, there is nothing more to do. If it was not, revoke the key now and change your password.',
        'action' => 'Review your API keys',
    ],

    'email_changed' => [
        'subject' => 'The email address on your account was changed',
        'changed' => 'The email address on your account was changed to :new on :date.',
        'address' => 'The change was made from the address :address.',
        'what_follows' => 'From now on the emails from this site, a password reset included, go to the new address and not to this one.',
        'if_not_you' => 'If this was you, there is nothing more to do. If it was not, someone else has your password: sign in if you still can, change it and set your email address back, and contact the people who run this site.',
        'action' => 'Change your password',
    ],

    'subscription' => [
        'view_billing' => 'Go to billing',

        'payment_failed' => [
            'subject_first' => "We couldn't take your payment",
            'subject_again' => "We still couldn't take your payment",
            'first' => "We couldn't take your payment of :amount.",
            'first_plan' => "We couldn't take your payment of :amount for :plan.",
            'again' => "We tried again and still couldn't take your payment of :amount.",
            'again_plan' => "We tried again and still couldn't take your payment of :amount for :plan.",
            'paused' => 'Until it goes through, what your plan gives you is paused.',
            'stays_active' => 'Your plan stays active while we keep trying.',
            'retry_on' => 'We will try again on :date.',
            'no_retry' => "We won't try again automatically, so please sort out your payment method now.",
            'update' => 'Update your payment method and pay now from your billing settings.',
        ],

        'payment_recovered' => [
            'subject' => 'Payment received, thank you',
            'received' => "We've taken your payment of :amount.",
            'received_plan' => "We've taken your payment of :amount for :plan.",
            'active_again' => 'Your plan is active again.',
        ],

        'cancelled_for_non_payment' => [
            'subject' => 'Your subscription has ended',
            'ended' => "We couldn't take payment for :plan after several tries, so your subscription has ended and what it gave you is no longer available.",
            'ended_unknown_plan' => "We couldn't take payment for your plan after several tries, so your subscription has ended and what it gave you is no longer available.",
            'welcome_back' => 'You are welcome back at any time: choose a plan and add a payment method that works.',
            'action' => 'Choose a plan',
        ],

        'trial_ending' => [
            'subject' => 'Your free trial ends soon',
            'ends' => 'Your free trial ends on :date.',
            'ends_plan' => 'Your free trial of :plan ends on :date.',
            'continues_at_price' => 'After that your plan continues at its regular price of :price. Any discount on your subscription is taken off, and anything left to pay is charged to the card you added.',
            'continues' => 'After that your subscription continues, and anything due is charged to the card you added.',
            'price_per_interval' => ':amount per :interval',
            'cancel' => "If you don't want to continue, cancel before then and you won't be charged.",
            'action' => 'Manage your subscription',
        ],
    ],

    // How often a plan bills, as `price_per_interval` says it. A value that is not here is said as it is stored.
    'interval' => [
        'day' => 'day',
        'week' => 'week',
        'month' => 'month',
        'year' => 'year',
    ],

    'order' => [
        'view' => 'View your order',

        'confirmation' => [
            'subject' => 'Your order :number',
            'greeting_named' => 'Thanks for your order, :name!',
            'greeting' => 'Thanks for your order!',
            'confirmed_free' => 'Your order :number is confirmed. Nothing was charged.',
            'confirmed_gift_card' => 'Your order :number is confirmed. It was paid for with your gift card.',
            'payment_received' => "We've received your payment for order :number.",
            'item' => ':quantity × :description — :subtotal :currency',
            'shipping' => 'Shipping: :amount :currency',
            'shipping_method' => 'Shipping (:method): :amount :currency',
            'discount' => 'Discount: -:amount :currency',
            'discount_code' => 'Discount (:code): -:amount :currency',
            'tax' => ':name (:rate%): :amount :currency',
            'shipping_to' => 'Shipping to: :address',
            'downloads' => 'Your downloads are on your order page. Open it with the button below whenever you need them.',
            'keys' => 'Your licence keys are on your order page. Open it with the button below to see them.',
            'order_total' => 'Order total: :amount :currency',
            'gift_card' => 'Gift card: -:amount :currency',
            'gift_card_ending' => 'Gift card (ending :last_four): -:amount :currency',
            'total_free' => 'Total: :amount :currency',
            'charged_nothing' => 'Charged to your card: 0.00 :currency',
            'charged' => 'Charged to your card: :amount :currency',
            'total_paid' => 'Total paid: :amount :currency',
        ],

        'shipped' => [
            'subject_shipped' => 'Your order :number has shipped',
            'subject_complete' => 'Your order :number is complete',
            'greeting_named' => 'Good news, :name!',
            'greeting' => 'Good news!',
            'shipped' => 'Order :number is on its way.',
            'complete' => 'Order :number is complete.',
            'carrier' => 'Carrier: :carrier',
            'tracking_number' => 'Tracking number: :number',
            'track' => 'Track your parcel',
        ],

        'refund' => [
            'subject' => 'Your refund for order :number',
            'greeting_named' => 'Hello :name,',
            'greeting' => 'Hello,',
            'to_gift_card' => "We've put :amount :currency back on your gift card ending :last_four for order :number.",
            'refunded' => "We've refunded :amount :currency for order :number.",
            'in_full' => 'Your order has now been refunded in full.',
            'so_far' => 'Refunded so far: :refunded :currency of :total :currency.',
            'statement' => 'Depending on your bank, it can take several days for the money to show on your statement.',
        ],
    ],

    'data_export' => [
        'title' => 'Your data export is ready',
        'excerpt' => 'Download your privacy data export before it expires.',
        'ready' => 'Your privacy data export is ready to download.',
        'contents' => 'The archive contains your profile details, blog comments, forum posts, and support tickets.',
        'download' => 'Download export',
        'view_settings' => 'View privacy settings',
        'expires' => 'This download link will expire in :count minute.|This download link will expire in :count minutes.',
        'if_not_you' => 'If you did not request this export, please contact support.',
    ],

    'digest' => [
        'subject' => 'Your daily update',
        'intro' => 'Here is the update that was waiting for you.|Here are the :count updates that were waiting for you.',
        'earlier' => 'And :count earlier update.|And :count earlier updates.',
        'action' => 'Notification settings',
        'reason' => 'You are receiving this email because you asked for a daily digest.',
    ],

    'forum' => [
        'thread_updated' => [
            'title' => 'New reply in ":title"',
            'line' => 'There is a new reply in a thread you follow: ":title".',
            'action' => 'View reply',
            'reason' => 'You are receiving this email because you opted to follow this thread.',
        ],

        'post_mentioned' => [
            'title' => 'You were mentioned in ":title"',
            'line' => 'You were mentioned in a forum discussion.',
            'action' => 'View mention',
        ],

        'followed_member_posted' => [
            'title' => ':name posted in ":title"',
            'line' => ':name posted in ":title".',
            'action' => 'View post',
            'reason' => 'You are receiving this email because you follow :name.',
        ],
    ],

    'blog_comment' => [
        'title' => 'New reply on ":title"',
        'line' => ':author just left a new reply on ":title".',
        'replied' => ':author replied: :excerpt',
        'anonymous_author' => 'A community member',
        'action' => 'Read the reply',
        'reason' => 'You are receiving this email because you opted in to comment notifications for this post.',
    ],

    'direct_message' => [
        'title' => ':name sent you a message.',
        'action' => 'View message',
        'reason' => 'You are receiving this email because :name sent you a message.',
    ],

    'support' => [
        'subject_line' => 'Subject: :subject',
        'message_line' => 'Message: :excerpt',
        'from_line' => 'From: :from',
        'view_conversation' => 'View conversation',
        'thanks' => 'Thank you for using our support center.',
        'customer' => 'Customer',
        'support_member' => 'A support member',

        'opened' => [
            'subject_owner' => 'Support ticket received: :subject',
            'title_agent' => 'New support ticket: :subject',
            'title_owner' => 'Support ticket opened: :subject',
            'agent' => 'A new support ticket requires your attention.',
            'owner' => 'Thanks for contacting our support team. Your ticket has been opened and our agents will take a look shortly.',
            'excerpt_owner' => 'We received your support request and will follow up soon.',
        ],

        'replied' => [
            'subject_agent' => 'New reply on support ticket: :subject',
            'subject_owner' => 'We received your reply: :subject',
            'title_agent' => 'New reply on ticket: :subject',
            'title_owner' => 'New reply on your ticket: :subject',
            'agent' => 'There is a new reply on a ticket you are assigned to.',
            'owner' => 'Your reply has been added to the conversation.',
            'owner_follow_up' => 'We will follow up as soon as possible if any further action is needed.',
            'excerpt_agent' => ':name replied to the ticket.',
        ],

        'status_updated' => [
            'title_agent' => 'Ticket status updated: :subject',
            'title_owner' => 'Support ticket status updated: :subject',
            'agent' => 'A support ticket you are assigned to has a new status.',
            'owner' => 'Your support ticket status has been updated.',
            'changed' => 'Status changed from :from to :to.',
        ],

        // A ticket's status as it is said in the lines above. A status that is not here is said as it is stored, with spaces and capitals.
        'status' => [
            'open' => 'Open',
            'pending' => 'Pending',
            'closed' => 'Closed',
        ],
    ],
];
