<?php
declare(strict_types=1);

namespace CakephpFixtureFactories\Command;

use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Filesystem\Folder;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;

class BakeFixtureFactoryCommand extends BakeCommand
{
    /**
     * path to Factory directory
     *
     * @var string
     */
    public $pathFragment = 'tests' . DS . 'Factory' . DS;
    /**
     * @var string path to the Table dir
     */
    public $pathToTableDir = 'Model' . DS . 'Table' . DS;
    /**
     * @var string
     */
    private $modelName;
    /**
     * @var Table
     */
    private $table;

    public function name(): string
    {
        return 'fixture_factory';
    }

    public function fileName(string $modelName): string
    {
        return $this->getFactoryNameFromModelName($modelName) . '.php';
    }

    public function template(): string
    {
        return 'fixture_factory';
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake fixture_factory';
    }

    /**
     * @return Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * @param string $tableName
     * @return $this|bool
     */
    public function setTable(string $tableName, ConsoleIo $io)
    {
        if ($this->plugin) {
            $tableName = $this->plugin . ".$tableName";
        }
        $this->table = TableRegistry::getTableLocator()->get($tableName);
        try {
            $this->table->getSchema();
        } catch (\Exception $e) {
            $io->warning("The table $tableName could not be found... in " . $this->getModelPath());
            $io->abort($e->getMessage());
            return false;
        }
        return $this;
    }

    /**
     * @param Arguments $arguments
     * @return string
     */
    public function getPath(Arguments $arguments): string
    {
        if ($this->plugin) {
            $path = $this->_pluginPath($this->plugin) . $this->pathFragment;
        } else {
            $path = TESTS . 'Factory' . DS;
        }

        return str_replace('/', DS, $path);
    }

    /**
     * Locate tables
     * @return string|string[]
     */
    public function getModelPath()
    {
        if (isset($this->plugin)) {
            $path = $this->_pluginPath($this->plugin) . APP_DIR . DS . $this->pathToTableDir;
        } else {
            $path = APP . $this->pathToTableDir;
        }

        return str_replace('/', DS, $path);
    }

    /**
     * List the tables
     * @return array
     */
    public function getTableList()
    {
        $dir = new Folder($this->getModelPath());
        $tables = $dir->find('.*Table.php', true);
        return array_map(function ($a) {
            return preg_replace('/Table.php$/', '', $a);
        }, $tables);
    }

    public function getFactoryNameFromModelName(string $name)
    {
        return Inflector::singularize(ucfirst($name)) . 'Factory';
    }

    /**
     * @return string
     */
    private function bakeAllModels(Arguments $args, ConsoleIo $io)
    {
        $tables = $this->getTableList();
        if (empty($tables)) {
            $io->err(sprintf('No tables were found at `%s`', $this->getModelPath()));
        } else {
            foreach ($tables as $table) {
                $this->bakeFixtureFactory($table, $args, $io);
            }
        }
        return '';
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $model = $args->getArgument('model') ?? '';
        $model = $this->_getName($model);
        $loud = !$args->getOption('quiet');

        if ($this->plugin) {
            $parts = explode('/', $this->plugin);
            $this->plugin = implode('/', array_map([$this, '_camelize'], $parts));
            if (strpos($this->plugin, '\\')) {
                $io->out('Invalid plugin namespace separator, please use / instead of \ for plugins.');
                return self::CODE_SUCCESS;
            }
        }

        if ($args->getOption('all')) {
            $this->bakeAllModels($args, $io);
            return self::CODE_SUCCESS;
        }

        if (empty($model)) {
            if ($loud) {
                $io->out('Choose a table from the following, choose -a for all, or -h for help:');
            }
            foreach ($this->getTableList() as $table) {
                if ($loud) {
                    $io->out('- ' . $table);
                }
            }
            return self::CODE_SUCCESS;
        }

        $this->bakeFixtureFactory($model, $args, $io);
        return self::CODE_SUCCESS;
    }

    public function bakeFixtureFactory(string $modelName, Arguments $args, ConsoleIo $io)
    {
        $this->modelName = $modelName;

        if (!$this->setTable($modelName, $io)) {
            $io->abort("$modelName not found...");
            return self::CODE_SUCCESS;
        }

        $renderer = new TemplateRenderer('CakephpFixtureFactories');
        $renderer->set($this->templateData($args));

        $contents = $renderer->generate($this->template());

        $path = $this->getPath($args);
        $filename = $path . $this->fileName($modelName);
        return $io->createFile($filename, $contents, $args->getOption('force') ?? false);
    }

    /**
     * Send variables to the view
     * @return array
     */
    public function templateData(Arguments $arg): array
    {
        $data = [
            'rootTableRegistryName' => $this->plugin ? $this->plugin . '.' . $this->modelName : $this->modelName,
            'modelNameSingular' => Inflector::singularize($this->modelName),
            'modelName' => $this->modelName,
            'factory' => Inflector::singularize($this->modelName) . 'Factory',
            'namespace' => $this->getFactoryNamespace(),
        ];
        if ($arg->getOption('methods')) {
            $associations = $this->getAssociations();
            $data['toOne'] = $associations['toOne'];
            $data['oneToMany'] = $associations['oneToMany'];
            $data['manyToMany'] = $associations['manyToMany'];
        }

        return $data;
    }

    /**
     * Returns the one and many association for a given model
     * @param string $modelName
     * @return array
     */
    public function getAssociations()
    {
        $associations = [
            'toOne' => [],
            'oneToMany' => [],
            'manyToMany' => [],
        ];

        foreach($this->getTable()->associations() as $association) {
            $name = $association->getClassName() ?? $association->getName();
            switch($association->type()) {
                case 'oneToOne':
                case 'manyToOne':
                    $associations['toOne'][$association->getName()] = $this->getFactoryWithSpaceName($name);
                    break;
                case 'oneToMany':
                    $associations['oneToMany'][$association->getName()] = $this->getFactoryWithSpaceName($name);
                    break;
                case 'manyToMany':
                    $associations['manyToMany'][$association->getName()] = $this->getFactoryWithSpaceName($name);
                    break;
            }
        }
        return $associations;
    }

    /**
     * Namespace where the factory belongs
     * @return string
     */
    public function getFactoryNamespace()
    {
        return (
            $this->plugin ?
            $this->plugin :
            Configure::read('App.namespace', 'App')
        ) . '\Test\Factory';
    }

    /**
     * @param string $modelName
     * @return string
     */
    public function getFactoryWithSpaceName(string $associationClass)
    {
        $cast = explode('.', $associationClass);
        if (count($cast) === 2) {
            $app =  $cast[0];
            $factory = $cast[1];
        } else {
            $app =  Configure::read('App.namespace', 'App');
            $factory = $cast[0];
        }
        return '\\' . $app . '\Test\Factory\\' . $this->getFactoryNameFromModelName($factory);
    }

    /**
     * @param string $name
     * @param Arguments $args
     * @param ConsoleIo $io
     */
    public function handleFactoryWithSameName(string $name, Arguments $args, ConsoleIo $io)
    {
        $factoryWithSameName = glob($this->getPath($args) . $name . '.php');
        if (!empty($factoryWithSameName)) {
            if (!$args->getOption('force')) {
                $io->abort(
                    sprintf(
                        'A factory with the name `%s` already exists.',
                        $name
                    )
                );
            }

            $io->info(sprintf('A factory with the name `%s` already exists, it will be deleted.', $name));
            foreach ($factoryWithSameName as $factory) {
                $io->info(sprintf('Deleting factory file `%s`...', $factory));
                if (unlink($factory)) {
                    $io->success(sprintf('Deleted `%s`', $factory));
                } else {
                    $io->err(sprintf('An error occurred while deleting `%s`', $factory));
                }
            }
        }
    }

    /**
     * Gets the option parser instance and configures it.
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $name = ($this->plugin ? $this->plugin . '.' : '') . $this->name;
        $parser = new ConsoleOptionParser($name);

        $parser->setDescription(
            'Fixture factory generator.'
        )
            ->addArgument('model', [
                'help' => 'Name of the model the factory will create entities from (plural, without the `Table` suffix). '.
                    'You can use the Foo.Bars notation to bake a factory for the model Bars located in the plugin Foo. \n
                    Factories are located in the folder test\Factory of your app, resp. plugin.',
            ])
            ->addOption('plugin', [
                'short' => 'p',
                'help' => 'Plugin to bake into.',
            ])
            ->addOption('all', [
                'short' => 'a',
                'boolean' => true,
                'help' => 'Bake factories for all models.',
            ])
            ->addOption('force', [
                'short' => 'f',
                'boolean' => true,
                'help' => 'Force overwriting existing file if a factory already exists with the same name.',
            ])
            ->addOption('quiet', [
                'short' => 'q',
                'boolean' => true,
                'help' => 'Enable quiet output.',
            ])
            ->addOption('methods', [
                'short' => 'm',
                'boolean' => true,
                'help' => 'Include methods based on the table relations.',
            ]);

        return $parser;
    }
}