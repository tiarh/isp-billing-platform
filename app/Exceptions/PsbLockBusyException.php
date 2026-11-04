<?php

namespace App\Exceptions;

/**
 * Lock kontensi PSB (router/OLT/SN/pool) — bukan error nyata.
 * ProcessPsbWaJob menangkap ini untuk auto-resume (release + retry),
 * bukan menandai draft gagal. Kill switch: Setting psb_auto_resume_enabled.
 */
class PsbLockBusyException extends \RuntimeException {}
