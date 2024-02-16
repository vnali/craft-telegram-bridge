<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\base;

interface ToolTypeInterface
{
    /**
     * Returns the criteria for a tool
     *
     * @param string $tool
     * @param string $chatId
     * @return array
     */
    public static function criteria(string $tool, string $chatId): array;

    /**
     * Returns the display name of a tool class
     *
     * @param string|null $language
     * @return string
     */
    public static function displayName(?string $language = null): string;

    /**
     * Returns the handle of a tool class
     *
     * @return string
     */
    public static function handle(): string;

    /**
     * Returns the keyboard items of a tool for a step
     *
     * @param string $step
     * @param string $chatId
     * @return array
     */
    public static function keyboardItems(string $step, string $chatId): array;

    /**
     * Returns the tools available in a tool class
     *
     * @param string $language
     * @return array
     */
    public static function tools(string $language): array;


    /**
     * Renders the tool result
     *
     * @param string $toolType
     * @param string $chatId
     * @return array
     */
    public static function render(string $toolType, string $chatId): array;
}
