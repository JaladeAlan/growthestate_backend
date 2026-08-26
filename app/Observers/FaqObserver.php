<?php

namespace App\Observers;

use App\Models\Faq;
use Illuminate\Support\Facades\Cache;

class FaqObserver
{
    public function saved(Faq $faq): void  { Cache::forget('support.faqs'); }
    public function deleted(Faq $faq): void { Cache::forget('support.faqs'); }
}