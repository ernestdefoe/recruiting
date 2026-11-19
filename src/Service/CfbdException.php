<?php

namespace Ernestdefoe\Recruiting\Service;

/**
 * A CFBD fetch failed. The message is a stable error code the frontend
 * translates (cfbd_unreachable, invalid_api_key, cfbd_error_<status>), so it
 * is safe to send to the client; no other exception's message ever is.
 */
class CfbdException extends \RuntimeException
{
}
