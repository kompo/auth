<?php

if (!function_exists('avatarFromText')) {
    /**
     * Generates a URL for an avatar image based on the provided text.
     *
     * @param string $text The text to generate the avatar from.
     * @return string The URL of the generated avatar image.
     */
    function avatarFromText($text)
    {
        return 'https://ui-avatars.com/api/?name=' . urlencode($text) . '&color=7F9CF5&background=EBF4FF';
    }
}