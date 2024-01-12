<?php

namespace Atwx\ProjectInfo\Extensions;

use mysqli;
use SilverStripe\ORM\DB;
use SilverStripe\Forms\FieldList;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\LiteralField;

/**
 * Class \App\CustomSiteConfig
 *
 * @property \SilverStripe\SiteConfig\SiteConfig|\App\CustomSiteConfig $owner
 * @property string $DateText
 * @property string $PlaceText
 * @property bool $ShowBanner
 * @property string $BannerText
 * @property string $AckMessageSubject
 * @property string $AckMessageContent
 */
class ProjectInfoExtension extends DataExtension
{

    private static $allowed_actions = array(
        'doBackup'
    );

    private static $db = [
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldToTab("Root.ProjectInfo", new LiteralField("ProjectInfo", "<h2>Project Info</h2>"));
        $fields->addFieldToTab("Root.ProjectInfo", new LiteralField("ProjectInfo", "<h3>Here is Info about this project:</h3>"));
        $fields->addFieldToTab("Root.ProjectInfo", new LiteralField("ProjectInfo", "<p>Database Name: <strong>" . $this->getDatabaseName() . "</strong></p>"), "Intro");
        $fields->addFieldToTab("Root.ProjectInfo", new FormAction('doBackup', 'Create a Database-Dump'));
    }

    public function getDatabaseName()
    {
        return Environment::getEnv('SS_DATABASE_NAME');
    }
}
