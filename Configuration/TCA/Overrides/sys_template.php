<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'events2',
    'Configuration/TypoScript/Typo384',
    'Events (>=8.4)'
);
