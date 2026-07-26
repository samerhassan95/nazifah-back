<?php

return [
    // Authentication messages
    'login_successful' => 'Login successful',
    'logout_successful' => 'Logged out successfully',
    'profile_retrieved' => 'Admin details retrieved successfully',
    'password_changed' => 'Password changed successfully',
    'password_reset' => 'Password reset successfully',
    'otp_sent' => 'OTP sent successfully',
    'otp_verified' => 'OTP verified successfully',
    'otp_sent_successfully' => 'OTP sent successfully',
    'otp_resent_successfully' => 'OTP resent successfully',

    // Error messages
    'failed' => 'These credentials do not match our records.',
    'not_verified' => 'Admin account is not verified',
    'login_failed' => 'Login failed',
    'logout_failed' => 'Logout failed',
    'profile_failed' => 'Failed to retrieve admin details',
    'password_change_failed' => 'Failed to change password',
    'current_password_incorrect' => 'Current password is incorrect',
    'admin_not_found' => 'Admin not found',
    'otp_send_failed' => 'Failed to send OTP',
    'send_otp_failed' => 'Failed to send OTP',
    'invalid_otp' => 'Invalid or expired OTP',
    'otp_verify_failed' => 'Failed to verify OTP',
    'invalid_reset_token' => 'Invalid reset token',
    'password_reset_failed' => 'Failed to reset password',
    'unauthenticated' => 'Unauthenticated. Please login.',
    'account_banned' => 'Your account has been banned. Please contact support.',
    'account_not_found' => 'Account not found. Please register first.',
    'phone_already_registered' => 'Phone number is already registered. Please login.',

    // Session & Rate Limiting
    'invalid_session' => 'Invalid or expired session',
    'session_expired' => 'Session expired',
    'rate_limit_exceeded' => 'Rate limit exceeded. Please wait before trying again.',
    'rate_limit_message' => 'Please wait :seconds seconds before requesting another OTP',

    // Validation messages
    'validation_error' => 'Validation Error',
    'phone_required' => 'Phone number is required',
    'phone_invalid' => 'Invalid phone number format',
    'password_required' => 'Password is required',
    'password_min' => 'Password must be at least :min characters',
    'current_password_required' => 'Current password is required',
    'new_password_required' => 'New password is required',
    'new_password_confirmed' => 'Password confirmation does not match',
    'otp_code_required' => 'OTP code is required',
    'otp_code_size' => 'OTP code must be :size digits',
    'full_name_required_for_registration' => 'Full name is required for new user registration',

    // OTP messages
    'otp_message' => 'OTP sent successfully',
    'otp_sent_message' => 'OTP has been sent to your phone',
    'otp_resent_message' => 'OTP has been resent to your phone',
    'otp_expires_in' => 'OTP expires in :minutes minutes',
    'otp_reset_message' => 'OTP verified successfully. Use this token to reset your password.',

    // OTP SMS Templates (sent via SMS)
    'otp_sms_login' => 'Your login verification code is: :otp. Valid for :minutes minutes.',
    'otp_sms_register' => 'Your registration verification code is: :otp. Valid for :minutes minutes.',
    'otp_sms_verify_account' => 'Your account verification code is: :otp. Valid for :minutes minutes.',
    'otp_sms_reset_password' => 'Your password reset code is: :otp. Valid for :minutes minutes.',
    'otp_sms_forgot_password' => 'Your password recovery code is: :otp. Valid for :minutes minutes.',

    // Additional User Actions
    'profile_updated' => 'Profile updated successfully',
    'account_deleted' => 'Account deleted successfully',
    'fingerprint_registered' => 'Fingerprint registered successfully',
    'fingerprint_removed' => 'Fingerprint removed successfully',
    'fingerprint_not_registered' => 'Fingerprint not registered for this account',
    'invalid_fingerprint' => 'Invalid fingerprint',
    'user_not_found' => 'User not found',
    'send_otp_failed_message' => 'OTP generation successful but sending failed',
    'otp_verification_failed' => 'OTP verification failed. Please try again later.',
    'fingerprint_login_failed' => 'Fingerprint login failed. Please try again later.',
    'employee_not_found' => 'Employee not found. Please contact your vendor administrator.',
    'account_not_active' => 'Your account is not active. Please contact your vendor administrator.',
    'employee_profile_retrieved' => 'Employee profile retrieved successfully',
];
