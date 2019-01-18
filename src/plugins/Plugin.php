<?php

namespace craftnet\plugins;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\actions\SetStatus;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\validators\UniqueValidator;
use craftnet\composer\Package;
use craftnet\developers\UserBehavior;
use craftnet\Module;
use craftnet\records\Plugin as PluginRecord;
use DateTime;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\helpers\Markdown;

/**
 * @property PluginEdition[] $editions
 * @property string $eagerLoadedElements
 * @property-read Asset|null $icon
 * @property-read bool $hasMultipleEditions
 * @property-read Package $package
 * @property-read User $developer
 */
class Plugin extends Element
{
    // Constants
    // =========================================================================

    const STATUS_PENDING = 'pending';

    // Static
    // =========================================================================

    /**
     * @return string
     */
    public static function displayName(): string
    {
        return 'Plugin';
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ENABLED => Craft::t('app', 'Enabled'),
            self::STATUS_PENDING => Craft::t('app', 'Pending Approval'),
            self::STATUS_DISABLED => Craft::t('app', 'Disabled')
        ];
    }

    /**
     * @return PluginQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new PluginQuery(static::class);
    }

    /**
     * @param ElementQueryInterface $elementQuery
     * @param array|null $disabledElementIds
     * @param array $viewState
     * @param string|null $sourceKey
     * @param string|null $context
     * @param bool $includeContainer
     * @param bool $showCheckboxes
     *
     * @return string
     */
    public static function indexHtml(ElementQueryInterface $elementQuery, array $disabledElementIds = null, array $viewState, string $sourceKey = null, string $context = null, bool $includeContainer, bool $showCheckboxes): string
    {
        /** @var PluginQuery $elementQuery */
        $elementQuery
            ->withLatestReleaseInfo(true, null, null, false)
            ->with(['icon', 'primaryCategory']);

        return parent::indexHtml($elementQuery, $disabledElementIds, $viewState, $sourceKey, $context, $includeContainer, $showCheckboxes);
    }

    /**
     * @param array $sourceElements
     * @param string $handle
     *
     * @return array|bool|false
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle)
    {
        switch ($handle) {
            case 'editions':
                $query = (new Query())
                    ->select(['pluginId as source', 'id as target'])
                    ->from(['craftnet_plugineditions'])
                    ->where(['pluginId' => ArrayHelper::getColumn($sourceElements, 'id')])
                    ->orderBy(['price' => SORT_ASC]);
                return ['elementType' => PluginEdition::class, 'map' => $query->all()];

            case 'developer':
                $query = (new Query())
                    ->select(['id as source', 'developerId as target'])
                    ->from(['craftnet_plugins'])
                    ->where(['id' => ArrayHelper::getColumn($sourceElements, 'id')]);
                return ['elementType' => User::class, 'map' => $query->all()];

            case 'icon':
                $query = (new Query())
                    ->select(['id as source', 'iconId as target'])
                    ->from(['craftnet_plugins'])
                    ->where(['id' => ArrayHelper::getColumn($sourceElements, 'id')])
                    ->andWhere(['not', ['iconId' => null]]);
                return ['elementType' => Asset::class, 'map' => $query->all()];

            case 'categories':
            case 'primaryCategory':
                $query = (new Query())
                    ->select(['p.id as source', 'pc.categoryId as target'])
                    ->from(['craftnet_plugins p'])
                    ->innerJoin(['craftnet_plugincategories pc'], '[[pc.pluginId]] = [[p.id]]')
                    ->where(['p.id' => ArrayHelper::getColumn($sourceElements, 'id')])
                    ->orderBy(['pc.sortOrder' => SORT_ASC]);
                if ($handle === 'primaryCategory') {
                    $query->andWhere(['pc.sortOrder' => 1]);
                }
                return ['elementType' => Category::class, 'map' => $query->all()];

            case 'screenshots':
                $query = (new Query())
                    ->select(['p.id as source', 'ps.assetId as target'])
                    ->from(['craftnet_plugins p'])
                    ->innerJoin(['craftnet_pluginscreenshots ps'], '[[ps.pluginId]] = [[p.id]]')
                    ->where(['p.id' => ArrayHelper::getColumn($sourceElements, 'id')])
                    ->orderBy(['ps.sortOrder' => SORT_ASC]);
                return ['elementType' => Asset::class, 'map' => $query->all()];

            default:
                return parent::eagerLoadingMap($sourceElements, $handle);
        }
    }

    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => 'All Plugins',
                'criteria' => ['status' => null],
            ],
            [
                'heading' => 'Categories',
            ],
        ];

        $categories = Category::find()
            ->group('pluginCategories')
            ->with('icon')
            ->all();
        $assetsService = Craft::$app->getAssets();

        foreach ($categories as $category) {
            $source = [
                'key' => 'category:' . $category->id,
                'label' => $category->title,
                'criteria' => ['categoryId' => $category->id],
            ];

            if (!empty($category->icon)) {
                try {
                    $source['icon'] = $assetsService->getThumbPath($category->icon[0], 16);
                } catch (\Throwable $e) {
                }
            }

            $sources[] = $source;
        }

        return $sources;
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            SetStatus::class,
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return [
            'developerName',
            'packageName',
            'repository',
            'name',
            'handle',
            'license',
            'keywords',
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'name' => 'Name',
            'handle' => 'Handle',
            'packageName' => 'Package Name',
            'repository' => 'Repository',
            'license' => 'License',
            'primaryCategory' => 'Primary Category',
            'documentationUrl' => 'Documentation URL',
            'latestVersion' => 'Version',
            'latestVersionTime' => 'Last Update',
            'activeInstalls' => 'Installs',
            'keywords' => 'Keywords',
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'name',
            'handle',
            'packageName',
            'repository',
            'license',
            'primaryCategory',
        ];
    }

    // Properties
    // =========================================================================

    /**
     * @var bool Whether the element is enabled
     */
    public $enabled = false;

    /**
     * @var int The developer’s user ID
     */
    public $developerId;

    /**
     * @var int The Composer package ID
     */
    public $packageId;

    /**
     * @var int|null The icon asset’s ID
     */
    public $iconId;

    /**
     * @var string|null Composer package name
     */
    public $packageName;

    /**
     * @var string The VCS repository URL
     */
    public $repository;

    /**
     * @var string The plugin name
     */
    public $name;

    /**
     * @var string The plugin handle
     */
    public $handle;

    /**
     * @var float|null The plugin license price
     */
    public $price;

    /**
     * @var float|null The plugin license renewal price
     */
    public $renewalPrice;

    /**
     * @var string The license type ('mit', 'craft')
     */
    public $license = 'craft';

    /**
     * @var string|null The plugin’s short description
     */
    public $shortDescription;

    /**
     * @var string|null The plugin’s long description
     */
    public $longDescription;

    /**
     * @var string|null The plugin’s documentation URL
     */
    public $documentationUrl;

    /**
     * @var string|null The plugin’s changelog path
     */
    public $changelogPath;

    /**
     * @var string|null The latest version available for the plugin
     */
    public $latestVersion;

    /**
     * @var DateTime|null The release time of the latest version
     */
    public $latestVersionTime;

    /**
     * @var int The number of active installs.
     */
    public $activeInstalls = 0;

    /**
     * @var string|null
     */
    public $devComments;

    /**
     * @var bool Whether the plugin is pending approval.
     */
    public $pendingApproval = false;

    /**
     * @var string|null
     */
    public $keywords;

    /**
     * @var DateTime|null The date that the plugin was approved
     */
    public $dateApproved;

    /**
     * @var PluginEdition[]|null
     */
    private $_editions;

    /**
     * @var PluginEdition[]|null
     */
    private $_originalEditions;

    /**
     * @var User|null
     */
    private $_developer;

    /**
     * @var Package|null
     */
    private $_package;

    /**
     * @var Asset|null
     */
    private $_icon;

    /**
     * @var Category[]|null
     */
    private $_categories;

    /**
     * @var Asset[]|null
     */
    private $_screenshots;

    /**
     * @var bool Whether the plugin was just submitted for approval
     */
    private $_submittedForApproval = false;

    /**
     * @var bool Whether the plugin was just approved
     * @see setApproved()
     */
    private $_approved = false;

    /**
     * @var bool Whether the plugin was just rejected
     * @see setRejected()
     */
    private $_rejected = false;

    /**
     * @var PluginHistory|null
     */
    private $_history;

    // Public Methods
    // =========================================================================

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function attributes()
    {
        $names = parent::attributes();
        ArrayHelper::removeValue($names, 'activeInstalls');
        ArrayHelper::removeValue($names, 'devComments');
        return $names;
    }

    /**
     * @inheritdoc
     */
    public function extraFields()
    {
        $fields = parent::extraFields();
        $fields[] = 'icon';
        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'dateApproved';
        $attributes[] = 'latestVersionTime';
        return $attributes;
    }

    /**
     * @param string $handle
     * @param array $elements
     */
    public function setEagerLoadedElements(string $handle, array $elements)
    {
        switch ($handle) {
            case 'editions':
                $this->_editions = $elements;
                break;
            case 'developer':
                $this->_developer = $elements[0] ?? null;
                break;
            case 'icon':
                $this->_icon = $elements[0] ?? null;
                break;
            case 'categories':
            case 'primaryCategory':
                $this->setCategories($elements);
                break;
            case 'screenshots':
                $this->setScreenshots($elements);
                break;
            default:
                parent::setEagerLoadedElements($handle, $elements);
        }
    }

    /**
     * @return bool
     * @throws InvalidConfigException
     */
    public function getHasMultipleEditions(): bool
    {
        if ($this->_editions !== null) {
            $count = count($this->_editions);
        } else {
            if ($this->id === null) {
                throw new InvalidConfigException('Plugin is missing its ID.');
            }

            $count = PluginEdition::find()
                ->pluginId($this->id)
                ->count();
        }

        return $count > 1;
    }

    /**
     * @return PluginEdition[]
     * @throws InvalidConfigException
     */
    public function getEditions(): array
    {
        if ($this->_editions !== null) {
            return $this->_editions;
        }
        if ($this->id === null) {
            throw new InvalidConfigException('Plugin is missing its ID.');
        }

        return $this->_editions = PluginEdition::find()
            ->pluginId($this->id)
            ->all();
    }

    /**
     * @param PluginEdition[] $editions
     */
    public function setEditions(array $editions)
    {
        if ($this->id !== null && $this->_originalEditions === null) {
            $this->_originalEditions = ArrayHelper::index($this->getEditions(), 'id');
        }

        $this->_editions = $editions;
    }

    /**
     * @param string $handle
     * @return PluginEdition
     * @throws InvalidConfigException
     * @throws InvalidArgumentException
     */
    public function getEdition(string $handle): PluginEdition
    {
        if ($this->id === null) {
            throw new InvalidConfigException('Plugin is missing its ID.');
        }

        foreach ($this->getEditions() as $edition) {
            if ($edition->handle === $handle) {
                return $edition;
            }
        }

        throw new InvalidArgumentException("Invalid plugin edition: {$handle}");
    }

    /**
     * @return User|UserBehavior
     * @throws InvalidConfigException
     */
    public function getDeveloper(): User
    {
        if ($this->_developer !== null) {
            return $this->_developer;
        }
        if ($this->developerId === null) {
            throw new InvalidConfigException('Plugin is missing its developer ID');
        }
        if (($user = User::find()->id($this->developerId)->status(null)->one()) === null) {
            throw new InvalidConfigException('Invalid developer ID: ' . $this->developerId);
        }
        return $this->_developer = $user;
    }

    /**
     * @return Package
     * @throws InvalidConfigException
     */
    public function getPackage(): Package
    {
        if ($this->_package !== null) {
            return $this->_package;
        }
        if ($this->packageId === null) {
            throw new InvalidConfigException('Plugin is missing its package ID');
        }
        return $this->_package = Module::getInstance()->getPackageManager()->getPackageById($this->packageId);
    }

    /**
     * @return string
     */
    public function getDeveloperName(): string
    {
        return $this->getDeveloper()->getDeveloperName();
    }

    /**
     * @return Asset|null
     * @throws InvalidConfigException
     */
    public function getIcon()
    {
        if ($this->_icon !== null) {
            return $this->_icon;
        }
        if ($this->iconId === null) {
            return null;
        }
        if (($asset = Asset::find()->id($this->iconId)->one()) === null) {
            throw new InvalidConfigException('Invalid asset ID: ' . $this->iconId);
        }
        return $this->_icon = $asset;
    }

    /**
     * @return Category[]
     */
    public function getCategories(): array
    {
        if ($this->_categories !== null) {
            return $this->_categories;
        }
        return $this->_categories = Category::find()
            ->innerJoin(['craftnet_plugincategories pc'], [
                'and',
                '[[pc.categoryId]] = [[categories.id]]',
                ['pc.pluginId' => $this->id]
            ])
            ->orderBy(['pc.sortOrder' => SORT_ASC])
            ->all();
    }

    /**
     * @param Category[] $categories
     */
    public function setCategories(array $categories)
    {
        $this->_categories = $categories;
    }

    /**
     * @return Asset[]
     */
    public function getScreenshots(): array
    {
        if ($this->_screenshots !== null) {
            return $this->_screenshots;
        }
        return $this->_screenshots = Asset::find()
            ->innerJoin(['craftnet_pluginscreenshots ps'], [
                'and',
                '[[ps.assetId]] = [[assets.id]]',
                ['ps.pluginId' => $this->id]
            ])
            ->orderBy(['ps.sortOrder' => SORT_ASC])
            ->all();
    }

    /**
     * @param Asset[] $screenshots
     */
    public function setScreenshots(array $screenshots)
    {
        $this->_screenshots = $screenshots;
    }

    /**
     *
     */
    public function submitForApproval()
    {
        $this->_submittedForApproval = true;
        $this->pendingApproval = true;
        $this->enabled = false;
    }

    /**
     *
     */
    public function approve()
    {
        $this->_approved = true;
        $this->enabled = true;
    }

    /**
     *
     */
    public function reject()
    {
        $this->_rejected = true;
        $this->enabled = false;
    }

    /**
     * @return PluginHistory
     */
    public function getHistory(): PluginHistory
    {
        if ($this->_history !== null) {
            return $this->_history;
        }
        return $this->_history = new PluginHistory($this);
    }

    public function rules()
    {
        $rules = parent::rules();

        $rules[] = [
            [
                'editions',
                'developerId',
                'packageName',
                'repository',
                'name',
                'handle',
                'license',
            ],
            'required',
        ];

        $rules[] = [
            [
                'id',
                'developerId',
                'packageId',
                'iconId',
            ],
            'number',
            'integerOnly' => true,
        ];

        $rules[] = [
            [
                'repository',
                'documentationUrl',
            ],
            'url',
        ];

        $rules[] = [
            [
                'categories',
            ],
            'required',
            'on' => self::SCENARIO_LIVE,
        ];

        $rules[] = [
            [
                'handle',
            ],
            UniqueValidator::class,
            'targetClass' => PluginRecord::class,
            'targetAttribute' => ['handle'],
            'message' => Craft::t('yii', '{attribute} "{value}" has already been taken.'),
        ];

        $rules[] = [
            [
                'packageName',
            ],
            UniqueValidator::class,
            'targetClass' => PluginRecord::class,
            'targetAttribute' => ['packageName'],
            'message' => Craft::t('yii', '{attribute} "{value}" has already been taken.'),
        ];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function afterValidate()
    {
        parent::afterValidate();

        $editionScenario = Craft::$app->getRequest()->getIsCpRequest() ? PluginEdition::SCENARIO_CP : PluginEdition::SCENARIO_SITE;

        foreach ($this->getEditions() as $i => $edition) {
            $edition->setScenario($editionScenario);
            if (!$edition->validate()) {
                $this->addModelErrors($edition, "editions[$i]");
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function validate($attributeNames = null, $clearErrors = true)
    {
        parent::validate($attributeNames, $clearErrors);

        if ($this->_rejected && !$this->devComments) {
            $this->addError('devComments', 'You must explain why the plugin wasn’t approved.');
        }

        if ($this->hasErrors() && $this->pendingApproval) {
            // Undo the enabled=true
            $this->enabled = false;
        }

        return !$this->hasErrors();
    }

    /**
     * @inheritdoc}
     */
    public function afterSave(bool $isNew)
    {
        $packageManager = Module::getInstance()->getPackageManager();
        if ($packageManager->packageExists($this->packageName)) {
            $package = $packageManager->getPackage($this->packageName);
            if ($package->type !== 'craft-plugin' || $package->developerId != $this->developerId || $package->repository !== $this->repository || !$package->managed) {
                $package->type = 'craft-plugin';
                $package->developerId = $this->developerId;
                $package->repository = $this->repository;
                $package->managed = true;
                $packageManager->savePackage($package);
            }
        } else {
            $package = new Package([
                'developerId' => $this->developerId,
                'name' => $this->packageName,
                'type' => 'craft-plugin',
                'repository' => $this->repository,
                'managed' => true,
            ]);
            $packageManager->savePackage($package);
            $packageManager->updatePackage($package->name, false, true, true);
        }

        $this->packageId = $package->id;

        if ($this->_approved || $this->_rejected || $this->enabled) {
            $this->pendingApproval = false;
        }

        $pluginData = [
            'id' => $this->id,
            'developerId' => $this->developerId,
            'packageId' => $this->packageId,
            'iconId' => $this->iconId,
            'packageName' => $this->packageName,
            'repository' => $this->repository,
            'name' => $this->name,
            'handle' => $this->handle,
            'license' => $this->license,
            'shortDescription' => $this->shortDescription,
            'longDescription' => $this->longDescription,
            'documentationUrl' => $this->documentationUrl,
            'changelogPath' => $this->changelogPath ?: null,
            'pendingApproval' => $this->pendingApproval,
            'keywords' => $this->keywords,
        ];

        if ($this->_approved) {
            $pluginData['dateApproved'] = Db::prepareDateForDb(new DateTime('now', new \DateTimeZone('UTC')));
        }

        $categoryData = [];
        foreach ($this->getCategories() as $i => $category) {
            $categoryData[] = [$this->id, $category->id, $i + 1];
        }

        $screenshotData = [];
        foreach ($this->getScreenshots() as $i => $screenshot) {
            $screenshotData[] = [$this->id, $screenshot->id, $i + 1];
        }

        $db = Craft::$app->getDb();

        if ($isNew) {
            // Save a new row in the plugins table
            $db->createCommand()
                ->insert('craftnet_plugins', $pluginData)
                ->execute();
        } else {
            // Update the plugins table row
            $db->createCommand()
                ->update('craftnet_plugins', $pluginData, ['id' => $this->id])
                ->execute();

            // Also delete any existing category/screenshot relations
            $db->createCommand()
                ->delete('craftnet_plugincategories', ['pluginId' => $this->id])
                ->execute();
            $db->createCommand()
                ->delete('craftnet_pluginscreenshots', ['pluginId' => $this->id])
                ->execute();
        }

        // Save the new category/screenshot relations
        $db->createCommand()
            ->batchInsert('craftnet_plugincategories', ['pluginId', 'categoryId', 'sortOrder'], $categoryData)
            ->execute();
        $db->createCommand()
            ->batchInsert('craftnet_pluginscreenshots', ['pluginId', 'assetId', 'sortOrder'], $screenshotData)
            ->execute();

        // Save the editions
        $elementsService = Craft::$app->getElements();
        foreach ($this->getEditions() as $edition) {
            $edition->pluginId = $this->id;
            $elementsService->saveElement($edition, false);
        }

        // If this is enabled, clear the plugin store caches
        if ($this->enabled) {
            touch(Module::getInstance()->getJsonDumper()->composerWebroot . '/packages.json');
        }

        $sendDevEmail = false;
        $emailSubject = null;
        $emailMessage = null;

        if ($this->_submittedForApproval) {
            $this->getHistory()->push(Craft::$app->getUser()->getIdentity()->username . ' submitted the plugin for approval');
        } else if ($this->_approved) {
            $this->getHistory()->push(Craft::$app->getUser()->getIdentity()->username . ' approved the plugin', $this->devComments);
            $sendDevEmail = true;
            $emailSubject = "{$this->name} has been approved!";
            $emailMessage = <<<EOD
Congratulations, {$this->name} has been approved, and is now available in the Craft Plugin Store for all to enjoy.

{$this->devComments}

Thanks for submitting it!
EOD;
        } else if ($this->_rejected) {
            $this->getHistory()->push(Craft::$app->getUser()->getIdentity()->username . ' rejected the plugin', $this->devComments);
            $sendDevEmail = true;
            $emailSubject = "{$this->name} isn't quite ready for prime time yet...";
            $emailMessage = <<<EOD
Thanks for submitting {$this->name} to the Craft Plugin Store!

Before we can approve it, please fix the following:

{$this->devComments}

Once you've taken care of that, re-submit your plugin and we'll give it another look. If you have any questions, just reply to this email and we'll get back to you.
EOD;
        } else if ($this->devComments) {
            $this->getHistory()->push(Craft::$app->getUser()->getIdentity()->username . ' sent the developer a note', $this->devComments);
            $sendDevEmail = true;
            $emailSubject = "Quick note about {$this->name}...";
            $emailMessage = $this->devComments;
        }

        if ($sendDevEmail) {
            $emailBody = <<<EOD
Hi {$this->getDeveloper()->getFriendlyName()},

{$emailMessage}

–The Craft Team
EOD;

            Craft::$app->getMailer()->compose()
                ->setSubject($emailSubject)
                ->setTextBody($emailBody)
                ->setHtmlBody(Markdown::process($emailBody))
                ->setTo($this->getDeveloper())
                ->send();
        }

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterDelete()
    {
        Module::getInstance()->getPackageManager()->removePackage($this->packageName);
        parent::afterDelete();
    }

    /**
     * @inheritdoc
     */
    public function getThumbUrl(int $size)
    {
        if ($this->iconId) {
            return Craft::$app->getAssets()->getThumbUrl($this->getIcon(), $size, false);
        }
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getStatus()
    {
        if (!$this->enabled && $this->pendingApproval) {
            return self::STATUS_PENDING;
        }

        return parent::getStatus();
    }

    public function getCpEditUrl()
    {
        return "plugins/{$this->id}-{$this->handle}";
    }

    // Protected Methods
    // =========================================================================

    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'handle':
                return "<code>{$this->handle}</code>";
            case 'packageName':
                return "<a href='http://packagist.org/packages/{$this->packageName}' target='_blank'>{$this->packageName}</a>";
            case 'repository':
            case 'documentationUrl':
                $url = $this->$attribute;
                return $url ? "<a href='{$url}' target='_blank'>" . preg_replace('/^https?:\/\/(?:www\.)?github\.com\//', '', $url) . '</a>' : '';
            case 'license':
                return $this->license === 'craft' ? 'Craft' : 'MIT';
            case 'primaryCategory':
                if ($category = ($this->getCategories()[0] ?? null)) {
                    return Craft::$app->getView()->renderTemplate('_elements/element', [
                        'element' => $category
                    ]);
                }
                return '';
            default:
                return parent::tableAttributeHtml($attribute);
        }
    }
}
