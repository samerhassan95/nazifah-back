<?php

return [
    // Success messages
    'methods_retrieved' => 'تم استرجاع طرق الدفع بنجاح',
    'payment_successful' => 'تمت عملية الدفع بنجاح',
    'payment_failed' => 'فشلت عملية الدفع',
    'payment_pending' => 'عملية الدفع قيد الانتظار',
    'payment_cancelled' => 'تم إلغاء عملية الدفع',

    // Payment method labels
    'cash_on_delivery' => 'الدفع عند الاستلام',
    'visa' => 'فيزا',
    'mastercard' => 'ماستركارد',
    'mada' => 'مدى',
    'credit_card' => 'دفع الكتروني',
    'digital_payment' => 'دفع الكتروني',
    'nazefah_wallet' => 'محفظة',
    'stc_pay' => 'STC Pay',
    'apple_pay' => 'آبل باي',
    'google_pay' => 'جوجل باي',
    'samsung_pay' => 'سامسونج باي',

    // Error messages
    'method_not_found' => 'طريقة الدفع غير موجودة',
    'insufficient_balance' => 'رصيد المحفظة غير كافي',
    'invalid_card' => 'بيانات البطاقة غير صحيحة',

    // Status and sorting
    'status_updated' => 'تم تحديث حالة طريقة الدفع بنجاح',
    'sort_order_updated' => 'تم تحديث ترتيب طرق الدفع بنجاح',
    'update_failed' => 'فشل تحديث حالة طريقة الدفع',
    'sort_order_failed' => 'فشل تحديث الترتيب',
    'retrieval_failed' => 'فشل استرجاع طرق الدفع',

    // Wallet Deposit Verification
    'transaction_not_found' => 'عملية الدفع غير موجودة',
    'unauthorized_transaction' => 'وصول غير مصرح به لهذه العملية',
    'deposit_already_processed' => 'تم معالجة الإيداع مسبقاً',
    'deposit_already_verified' => 'تم التحقق من الإيداع مسبقاً',
    'deposit_verified_successfully' => 'تم التحقق من الإيداع وإضافته للمحفظة بنجاح',
    'deposit_verified_wallet_updated' => 'تم التحقق من الإيداع وتحديث المحفظة بنجاح',
    'payment_verification_failed' => 'فشل التحقق من الدفع',
    'failed_to_update_wallet' => 'فشل في تحديث المحفظة',
    'failed_to_verify_deposit' => 'فشل في التحقق من الإيداع',

    // Moyasar
    'embedded_payment_initialized' => 'تم تهيئة الدفع المدمج — يرجى عرض نموذج ميسر.',
    'awaiting_moyasar_payment_confirmation' => 'في انتظار تأكيد الدفع من ميسر (لا يوجد معرف دفع حتى الآن).',
    'could_not_retrieve_payment' => 'تعذر استرجاع معلومات الدفع من ميسر.',
    'moyasar_description_wallet' => 'نظيفة - شحن المحفظة',
    'moyasar_description_order' => 'نظيفة - دفع قيمة الخدمة',

    // Moyasar failure messages (API returns English; we localize)
    'moyasar_error_generic' => 'فشلت عملية الدفع. يرجى المحاولة مرة أخرى.',
    'moyasar_error_3ds_declined' => 'تم رفض مصادقة البطاقة (التحقق الأمني 3DS). يرجى المحاولة مرة أخرى أو استخدام بطاقة أخرى.',
    'moyasar_error_3ds_auth' => 'المصادقة غير ناجحة أو أُلغيت من حامل البطاقة. يرجى المحاولة مرة أخرى.',
    'moyasar_error_3ds_not_enrolled' => 'البطاقة غير مسجّلة في خدمة التحقق الأمني (3DS). تواصل مع البنك لتفعيل الدفع الإلكتروني.',
    'moyasar_error_3ds_timeout' => 'انتهت مهلة التحقق الأمني (3DS). يرجى المحاولة مرة أخرى.',
    'moyasar_error_3ds_connection' => 'تعذّر الاتصال بخدمة التحقق الأمني. يرجى المحاولة لاحقاً.',
    'moyasar_error_3ds_busy' => 'خدمة التحقق الأمني مشغولة حالياً. يرجى المحاولة بعد قليل.',
    'moyasar_error_3ds_generic' => 'فشل التحقق الأمني للبطاقة (3DS). يرجى المحاولة مرة أخرى.',
    'moyasar_error_3ds_unsupported_device' => 'الجهاز غير مدعوم لإتمام التحقق الأمني.',
    'moyasar_error_3ds_frequency' => 'تم تجاوز حد محاولات المصادقة. يرجى المحاولة لاحقاً.',
    'moyasar_error_3ds_rejected' => 'رفض البنك المُصدِر محاولة المصادقة. يرجى استخدام بطاقة أخرى.',
    'moyasar_error_3ds_unavailable' => 'المصادقة غير متاحة حالياً. حاول لاحقاً أو تواصل مع البنك.',
    'moyasar_error_3ds_session_expired' => 'انتهت جلسة المصادقة. يرجى المحاولة مرة أخرى.',
    'moyasar_error_insufficient_funds' => 'لا يوجد رصيد كافٍ في البطاقة.',
    'moyasar_error_declined' => 'تم رفض العملية من بنك العميل. يرجى استخدام بطاقة أخرى.',
    'moyasar_error_blocked' => 'تم حجب العملية من البنك. قد يكون بسبب اشتباه احتيال.',
    'moyasar_error_expired_card' => 'البطاقة منتهية الصلاحية. يرجى استخدام بطاقة أخرى.',
    'moyasar_error_invalid_card' => 'رقم البطاقة غير صحيح. يرجى التحقق والمحاولة مرة أخرى.',
    'moyasar_error_invalid_cvc' => 'رمز الأمان (CVC/CVV) غير صحيح.',
    'moyasar_error_timed_out' => 'تعذّر الاتصال ببنك العميل. يرجى المحاولة مرة أخرى.',
    'moyasar_error_unspecified' => 'رفض البنك العملية لسبب غير محدد. يرجى استخدام بطاقة أخرى.',
    'moyasar_error_referred' => 'أشار بنك العميل إلى وجود مشكلة في البطاقة.',
    'moyasar_error_timeframe_expired' => 'انتهت المهلة المسموحة لإتمام الدفع. يرجى المحاولة مرة أخرى.',
    'moyasar_error_stolen_card' => 'تم الإبلاغ عن البطاقة كمسروقة. يرجى استخدام بطاقة أخرى.',
    'moyasar_error_fraud' => 'العملية مشتبه بها كاحتيال وتم رفضها.',
    'moyasar_error_amount_exceeded' => 'المبلغ تجاوز الحد الأقصى المسموح لكل عملية.',

    // Payment status short labels (API payment_status_label)
    'status_completed' => 'مكتمل',
    'status_pending' => 'قيد الانتظار',
    'status_failed' => 'فاشل',
    'status_not_initiated' => 'لم يبدأ',

    // Wallet transaction history
    'wallet_txn_deposit' => 'إيداع في المحفظة',
    'wallet_txn_order_payment' => 'دفع طلب #:order عبر المحفظة',
    'wallet_txn_order_payment_awaiting_card' => 'دفع طلب #:order عبر المحفظة (بانتظار دفع البطاقة)',
    'wallet_txn_order_payment_awaiting_gateway' => 'دفع طلب #:order عبر المحفظة (بانتظار إتمام الدفع)',
    'wallet_txn_order_payment_reserved' => 'حجز مبلغ طلب #:order من المحفظة',
    'wallet_txn_order_surcharge' => 'رسوم إضافية لطلب #:order عبر المحفظة',
    'wallet_txn_order_surcharge_awaiting' => 'رسوم إضافية لطلب #:order عبر المحفظة (بانتظار إتمام الدفع)',
    'wallet_txn_order_update_charge' => 'رسوم تحديث طلب #:order',
    'wallet_txn_order_deleted' => 'تم استرداد :amount ر.س بعد حذف الطلب رقم #:order',
    'wallet_txn_order_refund' => 'تم استرداد مبلغ :amount ر.س من الطلب رقم #:order',
    'wallet_txn_order_cancelled_refund' => 'تم استرداد :amount ر.س بعد إلغاء الطلب رقم #:order',
    'wallet_txn_order_generic' => 'طلب #:order',
    'wallet_txn_addition' => 'إضافة',
    'wallet_txn_deduction' => 'خصم',
];
