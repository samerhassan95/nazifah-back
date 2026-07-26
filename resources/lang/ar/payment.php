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
    'nazefah_wallet' => 'محفظة نتيفة',
    'stc_pay' => 'STC Pay',
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
    'wallet_txn_order_deleted' => 'استرداد طلب #:order بعد الحذف',
    'wallet_txn_order_refund' => 'استرداد لطلب #:order',
    'wallet_txn_order_generic' => 'طلب #:order',
    'wallet_txn_addition' => 'إضافة',
    'wallet_txn_deduction' => 'خصم',
];
