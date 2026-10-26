<?php

/**
 * @file plugins/generic/userActivityPlugin/index.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @brief Wrapper for the User Activity & Database Audit System Plugin for OJS 3.5.
 */

require_once __DIR__ . '/UserActivityPlugin.php';

return new \APP\plugins\generic\userActivityPlugin\UserActivityPlugin();
