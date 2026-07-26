<?php

return [
    // Authentication messages
    'login_successful' => 'تم تسجيل الدخول بنجاح',
    'logout_successful' => 'تم تسجيل الخروج بنجاح',
    'profile_retrieved' => 'تم استرجاع بيانات المسؤول بنجاح',
    'password_changed' => 'تم تغيير كلمة المرور بنجاح',
    'password_reset' => 'تم إعادة تعيين كلمة المرور بنجاح',
    'otp_sent' => 'تم إرسال رمز التحقق بنجاح',
    'otp_verified' => 'تم التحقق من الرمز بنجاح',
    'otp_sent_successfully' => 'تم إرسال رمز التحقق بنجاح',
    'otp_resent_successfully' => 'تم إعادة إرسال رمز التحقق بنجاح',

    // Error messages
    'failed' => 'بيانات الاعتماد هذه غير مطابقة لسجلاتنا.',
    'not_verified' => 'حساب المسؤول غير مفعل',
    'login_failed' => 'فشل تسجيل الدخول',
    'logout_failed' => 'فشل تسجيل الخروج',
    'profile_failed' => 'فشل استرجاع بيانات المسؤول',
    'password_change_failed' => 'فشل تغيير كلمة المرور',
    'current_password_incorrect' => 'كلمة المرور الحالية غير صحيحة',
    'admin_not_found' => 'المسؤول غير موجود',
    'otp_send_failed' => 'فشل إرسال رمز التحقق',
    'send_otp_failed' => 'فشل إرسال رمز التحقق',
    'invalid_otp' => 'رمز التحقق غير صحيح أو منتهي الصلاحية',
    'otp_verify_failed' => 'فشل التحقق من الرمز',
    'invalid_reset_token' => 'رمز إعادة التعيين غير صالح',
    'password_reset_failed' => 'فشل إعادة تعيين كلمة المرور',
    'unauthenticated' => 'غير مصرح. يرجى تسجيل الدخول.',
    'account_banned' => 'تم حظر حسابك. يرجى التواصل مع الدعم.',
    'account_not_found' => 'الحساب غير موجود. يرجى التسجيل أولاً.',
    'phone_already_registered' => 'رقم الهاتف مسجل بالفعل. يرجى تسجيل الدخول.',

    // Session & Rate Limiting
    'invalid_session' => 'جلسة غير صالحة أو منتهية الصلاحية',
    'session_expired' => 'انتهت صلاحية الجلسة',
    'rate_limit_exceeded' => 'تم تجاوز الحد المسموح. يرجى الانتظار قبل المحاولة مرة أخرى.',
    'rate_limit_message' => 'يرجى الانتظار :seconds ثانية قبل طلب رمز تحقق آخر',

    // Validation messages
    'validation_error' => 'خطأ في التحقق من البيانات',
    'phone_required' => 'رقم الهاتف مطلوب',
    'phone_invalid' => 'تنسيق رقم الهاتف غير صالح',
    'password_required' => 'كلمة المرور مطلوبة',
    'password_min' => 'كلمة المرور يجب أن تكون على الأقل :min أحرف',
    'current_password_required' => 'كلمة المرور الحالية مطلوبة',
    'new_password_required' => 'كلمة المرور الجديدة مطلوبة',
    'new_password_confirmed' => 'تأكيد كلمة المرور غير مطابق',
    'otp_code_required' => 'رمز التحقق مطلوب',
    'otp_code_size' => 'رمز التحقق يجب أن يتكون من :size أرقام',
    'full_name_required_for_registration' => 'يرجى إدخال الاسم الكامل للتسجيل كمستخدم جديد',

    // OTP messages
    'otp_message' => 'تم إرسال رمز التحقق بنجاح',
    'otp_sent_message' => 'تم إرسال رمز التحقق إلى هاتفك',
    'otp_resent_message' => 'تم إعادة إرسال رمز التحقق إلى هاتفك',
    'otp_expires_in' => 'رمز التحقق صالح لمدة :minutes دقائق',
    'otp_reset_message' => 'تم التحقق من الرمز بنجاح. استخدم هذا الرمز لإعادة تعيين كلمة المرور.',

    // OTP SMS Templates (sent via SMS)
    'otp_sms_login' => 'رمز تسجيل الدخول: :otp. صالح لمدة :minutes دقائق.',
    'otp_sms_register' => 'رمز التسجيل: :otp. صالح لمدة :minutes دقائق.',
    'otp_sms_verify_account' => 'رمز التحقق من الحساب: :otp. صالح لمدة :minutes دقائق.',
    'otp_sms_reset_password' => 'رمز إعادة تعيين كلمة المرور: :otp. صالح لمدة :minutes دقائق.',
    'otp_sms_forgot_password' => 'رمز استعادة كلمة المرور: :otp. صالح لمدة :minutes دقائق.',

    // Additional User Actions
    'profile_updated' => 'تم تحديث الملف الشخصي بنجاح',
    'account_deleted' => 'تم حذف الحساب بنجاح',
    'fingerprint_registered' => 'تم تسجيل البصمة بنجاح',
    'fingerprint_removed' => 'تم إزالة البصمة بنجاح',
    'fingerprint_not_registered' => 'البصمة غير مسجلة لهذا الحساب',
    'invalid_fingerprint' => 'البصمة غير صالحة',
    'user_not_found' => 'المستخدم غير موجود',
    'send_otp_failed_message' => 'تم توليد الرمز بنجاح ولكن فشل الإرسال',
    'otp_verification_failed' => 'فشل التحقق من الرمز. يرجى المحاولة مرة أخرى لاحقاً.',
    'fingerprint_login_failed' => 'فشل تسجيل الدخول بالبصمة. يرجى المحاولة مرة أخرى لاحقاً.',
    'employee_not_found' => 'الموظف غير موجود. يرجى التواصل مع مدير النظام.',
    'account_not_active' => 'حسابك غير نشط. يرجى التواصل مع مدير النظام.',
    'employee_profile_retrieved' => 'تم استرجاع بيانات الموظف بنجاح',
];
