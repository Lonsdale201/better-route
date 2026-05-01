<?php

declare(strict_types=1);

namespace BetterRoute\Support;

enum CryptoEncoding: string
{
    case Hex = 'hex';
    case Base64 = 'base64';
    case Base64Url = 'base64url';
}
