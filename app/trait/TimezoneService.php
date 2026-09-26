<?php

namespace App\trait;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

class TimezoneService
{
    /**
     * Get the user's timezone.
     * This can be based on user preferences or fallback to a default timezone.
     *
     * @return string
     */
    public function getUserTimezone()
    {
        // Check if the user is authenticated and has a timezone set
        if (Auth::check() && Auth::user()->timezone) {
            return Auth::user()->timezone; // Return user's timezone
        }

        // Optionally, you could also check for a timezone from the request header or IP-based geolocation
        // Check if a timezone is provided in the request
        $timezone = Request::header('Timezone');

        if ($timezone && in_array($timezone, timezone_identifiers_list())) {
            return $timezone; // Return timezone from request header if valid
        }

        // Default fallback timezone (you can configure this in config/app.php or elsewhere)
        return Config::get('app.timezone');
    }

    /**
     * Set the application's timezone.
     * This is typically called after determining the timezone.
     *
     * @param  string  $timezone
     */
    public function setApplicationTimezone($timezone)
    {
        // Set the timezone for the current application session
        date_default_timezone_set($timezone);

        // Optionally, you can also set it for Laravel's internal settings:
        Config::set('app.timezone', $timezone);
    }
}
