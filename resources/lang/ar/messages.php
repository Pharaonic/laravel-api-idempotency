<?php

declare(strict_types=1);

return [
    'key_missing' => 'الترويسة Idempotency-Key مطلوبة.',
    'key_invalid' => 'يجب أن تكون الترويسة Idempotency-Key قيمة واحدة من 1 إلى 255 حرف ASCII مرئي.',
    'key_conflict' => 'تم استخدام مفتاح Idempotency-Key هذا من قبل مع طلب مختلف.',
    'in_progress' => 'لا يزال طلب بمفتاح التكرار هذا قيد المعالجة.',
    'outcome_unknown' => 'نتيجة الطلب الأصلي بمفتاح التكرار هذا غير معروفة، لذلك لن يُنفَّذ مرة أخرى.',
    'unauthenticated' => 'يجب أن تكون الطلبات ذات مفتاح التكرار مُصادَقًا عليها.',
    'request_unsupported' => 'يجب أن يكون جسم الطلب ذو مفتاح التكرار بصيغة JSON أو فارغًا.',
    'request_invalid' => 'جسم الطلب ليس JSON صالحًا أو متداخل بعمق زائد.',
    'store_unavailable' => 'مخزن مفاتيح التكرار غير متاح، لذلك لم تتم معالجة الطلب.',
];
