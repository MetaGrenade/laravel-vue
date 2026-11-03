<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'banned' => 'Your account has been banned.',
    'banned_until' => 'Your account has been suspended until :date.',
    'banned_reason' => 'The reason given: :reason',
    'banned_contact' => 'If you think this is a mistake, contact :contact.',
    'two_factor_code_required' => 'Please provide an authentication code or a recovery code.',
    'two_factor_code_invalid' => 'The provided authentication code was invalid.',
    'two_factor_recovery_code_invalid' => 'The recovery code is invalid or has already been used.',

    // Asked for whether or not the address belongs to an account, so that the form cannot be used to find out.
    'reset_link_sent' => 'A reset link will be sent if the account exists.',

    /*
     * The text of the sign-in, registration and password pages (resources/js/pages/auth). The groups above are read by the server;
     * these are read by the browser. Only the routes that show those pages send them (the `translations:auth` middleware), so they are
     * not part of every other page. A key may not sit beside a string of the same name: `password` above is a message, so the word
     * "Password" is `common.password`.
     */
    'common' => [
        'email' => 'Email address',
        'email_placeholder' => 'email@example.com',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'or_continue_with_email' => 'Or continue with email',
        'back_to_login' => 'Back to log in',
        'back_to_site' => 'Back to site',
        'home' => 'Home',
    ],

    'login' => [
        'head' => 'Log in',
        'title' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'continue_with' => 'Continue with :provider',
        'forgot_password' => 'Forgot password?',
        'remember_me' => 'Remember me',
        'submit' => 'Log in',
        'no_account' => "Don't have an account?",
        'sign_up' => 'Sign up',
    ],

    'register' => [
        'head' => 'Register',
        'title' => 'Create an account',
        'description' => 'Enter your details below to create your account',
        'sign_up_with' => 'Sign up with :provider',
        'nickname' => 'Nickname',
        'submit' => 'Create account',
        'have_account' => 'Already have an account?',
        'log_in' => 'Log in',
    ],

    'forgot_password' => [
        'head' => 'Forgot password',
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'submit' => 'Email password reset link',
    ],

    'reset_password' => [
        'head' => 'Reset password',
        'title' => 'Reset password',
        'description' => 'Please enter your new password below',
        'submit' => 'Reset password',
    ],

    'confirm_password' => [
        'head' => 'Confirm password',
        'title' => 'Confirm your password',
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
        'submit' => 'Confirm password',
    ],

    'verify_email' => [
        'head' => 'Email verification',
        'title' => 'Verify email',
        'description' => 'Please verify your email address by clicking on the link we just emailed to you.',
        'link_sent' => 'A new verification link has been sent to the email address you provided during registration.',
        'resend' => 'Resend verification email',
        'log_out' => 'Log out',
    ],

    'two_factor' => [
        'head' => 'Two-factor authentication',
        'title' => 'Two-factor authentication',
        'description' => 'Complete the challenge to access your account',
        'code_title' => 'Enter your authentication code',
        'code_description' => 'Open your authenticator app (Authy, Google Authenticator, etc.) and enter the 6-digit verification code.',
        'code_label' => 'Authentication code',
        'recovery_title' => 'Use a recovery code',
        'recovery_description' => 'Enter one of the recovery codes you saved when enabling multi-factor authentication.',
        'recovery_label' => 'Recovery code',
        'submit' => 'Continue',
        'use_recovery' => 'Use a recovery code',
        'use_code' => 'Use an authenticator code instead',
    ],
];
