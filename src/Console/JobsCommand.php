<?php

/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace Barryvdh\LaravelIdeHelper\Console;

use Barryvdh\LaravelIdeHelper\Contracts\ModelHookInterface;
use Barryvdh\LaravelIdeHelper\Parsers\PhpDocReturnTypeParser;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use Barryvdh\Reflection\DocBlock\Tag;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\Types\ContextFactory;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionType;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * A command to generate autocomplete information for your IDE
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
class JobsCommand extends Command
{
    /**
     * @var Filesystem $files
     */
    protected $files;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'ide-helper:jobs';

    /**
     * @var string
     */
    protected $filename;

    protected $write = false;
    protected $write_mixin = false;
    protected $reset;

    /**
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->filename = $this->laravel['config']->get('ide-helper.models_filename', '_ide_helper_jobs.php');
        $filename = $this->option('filename') ?? $this->filename;
        $this->write = $this->option('write');
        // $this->write_mixin = $this->option('write-mixin');
        $this->dirs = array_merge(
            $this->laravel['config']->get('ide-helper.job_locations', []),
            $this->option('dir')
        );
        $job = $this->argument('job');
        // $ignore = $this->option('ignore');
        $this->reset = $this->option('reset');
        // $this->phpstorm_noinspections = $this->option('phpstorm-noinspections');
        // $this->write_model_magic_where = $this->laravel['config']->get('ide-helper.write_model_magic_where', true);
        // $this->write_model_external_builder_methods = $this->laravel['config']->get('ide-helper.write_model_external_builder_methods', true);
        // $this->write_model_relation_count_properties =
        //     $this->laravel['config']->get('ide-helper.write_model_relation_count_properties', true);
        //
        // $this->write = $this->write_mixin ? true : $this->write;
        // //If filename is default and Write is not specified, ask what to do
        // if (!$this->write && $filename === $this->filename && !$this->option('nowrite')) {
        //     if (
        //         $this->confirm(
        //             "Do you want to overwrite the existing model files? Choose no to write to $filename instead"
        //         )
        //     ) {
        //         $this->write = true;
        //     }
        // }
        //
        // $this->dateClass = class_exists(\Illuminate\Support\Facades\Date::class)
        //     ? '\\' . get_class(\Illuminate\Support\Facades\Date::now())
        //     : '\Illuminate\Support\Carbon';

        $job = '';
        $ignore = '';
        $content = $this->generateDocs($job, $ignore);

        if (!$this->write || $this->write_mixin) {
            $written = $this->files->put($filename, $content);
            if ($written !== false) {
                $this->info("Model information was written to $filename");
            } else {
                $this->error("Failed to write model information to $filename");
            }
        }
    }


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['job', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which jobs to include', []],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['filename', 'F', InputOption::VALUE_OPTIONAL, 'The path to the helper file'],
            ['dir', 'D', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'The model dir, supports glob patterns', [], ],
            ['write', 'W', InputOption::VALUE_NONE, 'Write to Model file'],
            ['write-mixin', 'M', InputOption::VALUE_NONE,
                "Write models to {$this->filename} and adds @mixin to each model, avoiding IDE duplicate declaration warnings",
            ],
            ['nowrite', 'N', InputOption::VALUE_NONE, 'Don\'t write to Model file'],
            ['reset', 'R', InputOption::VALUE_NONE, 'Remove the original phpdocs instead of appending'],
            ['smart-reset', 'r', InputOption::VALUE_NONE, 'Refresh the properties/methods list, but keep the text'],
            ['phpstorm-noinspections', 'p', InputOption::VALUE_NONE,
                'Add PhpFullyQualifiedNameUsageInspection and PhpUnnecessaryFullyQualifiedNameInspection PHPStorm ' .
                'noinspection tags',
            ],
            ['ignore', 'I', InputOption::VALUE_OPTIONAL, 'Which models to ignore', ''],
        ];
    }

    protected function generateDocs($loadJobs, $ignore = '')
    {
        $output = "<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
\n\n";

        if (empty($loadJobs)) {
            $jobs = $this->loadJobs();
        } else {
            $jobs = [];
            foreach ($loadJobs as $job) {
                $jobs = array_merge($jobs, explode(',', $job));
            }
        }

        $ignore = array_merge(
            explode(',', $ignore),
            $this->laravel['config']->get('ide-helper.ignored_jobs', [])
        );

        foreach ($jobs as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring job '$name'");
                }
                continue;
            }
            $this->properties = [];
            $this->methods = [];

            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new ReflectionClass($name);

                    $this->comment("Loading job '$name'", OutputInterface::VERBOSITY_VERBOSE);

                    if (!$reflectionClass->IsInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    $job = $this->laravel->make($name);
                    //
                    // if (method_exists($job, 'getCasts')) {
                    //     $this->castPropertiesType($job);
                    // }
                    //
                    $this->getDispatchableMethod($job);
                    // $this->getSoftDeleteMethods($job);
                    // $this->getCollectionMethods($job);
                    // $this->getFactoryMethods($job);
                    //
                    // $this->runModelHooks($job);
                    //
                    $output                .= $this->createPhpDocs($name);
                    // $ignore[]              = $name;
                    $this->nullableColumns = [];
                } catch (Throwable $e) {
                    $this->error('Exception: ' . $e->getMessage() .
                        "\nCould not analyze class $name.\n\nTrace:\n" .
                        $e->getTraceAsString());
                }
            }
        }

        return $output;
    }


    protected function loadJobs()
    {
        $jobs = [];
        foreach ($this->dirs as $dir) {
            if (is_dir(base_path($dir))) {
                $dir = base_path($dir);
            }

            $dirs = glob($dir, GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    $this->error("Cannot locate directory '{$dir}'");
                    continue;
                }

                if (file_exists($dir)) {
                    $classMap = ClassMapGenerator::createMap($dir);

                    // Sort list so it's stable across different environments
                    ksort($classMap);

                    foreach ($classMap as $model => $path) {
                        $jobs[] = $model;
                    }
                }
            }
        }
        return $jobs;
    }

    /**
     * @param $job
     */
    public function getDispatchableMethod($job)
    {
        $traits = class_uses_recursive($job);
        if (in_array( 'Illuminate\\Foundation\\Bus\\Dispatchable', $traits)) {
            $reflectionClass = new ReflectionClass($job);
            $constructor = $reflectionClass->getConstructor();
            /**/
            $arguments = $this->getParameters($constructor);
            $this->setMethod('dispatch', 'void', $arguments);
            /**/
            $arguments =  $this->getParameters($reflectionClass->getMethod('dispatchIf'));
            array_pop($arguments);
            $arguments = array_merge($arguments, $this->getParameters($constructor));
            $this->setMethod('dispatchIf', 'void', $arguments);
            /**/
            $arguments =  $this->getParameters($reflectionClass->getMethod('dispatchUnless'));
            array_pop($arguments);
            $arguments = array_merge($arguments, $this->getParameters($constructor));
            $this->setMethod('dispatchUnless', 'void', $arguments);
        }
    }

    /**
     * @param string      $name
     * @param string|null $type
     * @param bool|null   $read
     * @param bool|null   $write
     * @param string|null $comment
     * @param bool        $nullable
     */
    public function setProperty($name, $type = null, $read = null, $write = null, $comment = '', $nullable = false)
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = [];
            $this->properties[$name]['type'] = 'mixed';
            $this->properties[$name]['read'] = false;
            $this->properties[$name]['write'] = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if ($type !== null) {
            $newType = $this->getTypeOverride($type);
            if ($nullable) {
                $newType .= '|null';
            }
            $this->properties[$name]['type'] = $newType;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    public function setMethod($name, $type = '', $arguments = [], $comment = '')
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);

        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name] = [];
            $this->methods[$name]['type'] = $type;
            $this->methods[$name]['arguments'] = $arguments;
            $this->methods[$name]['comment'] = $comment;
        }
    }

    public function unsetMethod($name)
    {
        foreach ($this->methods as $k => $v) {
            if (strtolower($k) === strtolower($name)) {
                unset($this->methods[$k]);
                return;
            }
        }
    }

    public function getMethodType(Model $model, string $classType)
    {
        $modelName = $this->getClassNameInDestinationFile($model, get_class($model));
        $builder = $this->getClassNameInDestinationFile($model, $classType);
        return $builder . '|' . $modelName;
    }

    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {
        $reflection = new ReflectionClass($class);
        $namespace = $reflection->getNamespaceName();
        $classname = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $keyword = $this->getClassKeyword($reflection);

        if ($this->reset) {
            $phpdoc = new DocBlock('', new Context($namespace));
            $phpdoc->setText(
                (new DocBlock($reflection, new Context($namespace)))->getText()
            );
        } else {
            $phpdoc = new DocBlock($reflection, new Context($namespace));
        }

        $properties = [];
        $methods = [];
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name == 'property' || $name == 'property-read' || $name == 'property-write') {
                $properties[] = $tag->getVariableName();
            } elseif ($name == 'method') {
                $methods[] = $tag->getMethodName();
            }
        }

        ksort($this->methods);

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }

            $arguments = implode(', ', $method['arguments']);

            $tagLine = "@method static {$method['type']} {$name}({$arguments})";
            if ($method['comment'] !== '') {
                $tagLine .= " {$method['comment']}";
            }
            $tag = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }
        //
        // if ($this->write) {
        //     $eloquentClassNameInModel = $this->getClassNameInDestinationFile($reflection, 'Eloquent');
        //
        //     // remove the already existing tag to prevent duplicates
        //     foreach ($phpdoc->getTagsByName('mixin') as $tag) {
        //         if ($tag->getContent() === $eloquentClassNameInModel) {
        //             $phpdoc->deleteTag($tag);
        //         }
        //     }
        //
        //     $phpdoc->appendTag(Tag::createInstance('@mixin ' . $eloquentClassNameInModel, $phpdoc));
        // }
        //
        // if ($this->phpstorm_noinspections) {
        //     /**
        //      * Facades, Eloquent API
        //      * @see https://www.jetbrains.com/help/phpstorm/php-fully-qualified-name-usage.html
        //      */
        //     $phpdoc->appendTag(Tag::createInstance('@noinspection PhpFullyQualifiedNameUsageInspection', $phpdoc));
        //     /**
        //      * Relations, other models in the same namespace
        //      * @see https://www.jetbrains.com/help/phpstorm/php-unnecessary-fully-qualified-name.html
        //      */
        //     $phpdoc->appendTag(
        //         Tag::createInstance('@noinspection PhpUnnecessaryFullyQualifiedNameInspection', $phpdoc)
        //     );
        // }
        //
        $serializer = new DocBlockSerializer();
        $docComment = $serializer->getDocComment($phpdoc);

        if ($this->write_mixin) {
            $phpdocMixin = new DocBlock($reflection, new Context($namespace));
            // remove all mixin tags prefixed with IdeHelper
            foreach ($phpdocMixin->getTagsByName('mixin') as $tag) {
                if (Str::startsWith($tag->getContent(), 'IdeHelper')) {
                    $phpdocMixin->deleteTag($tag);
                }
            }

            $mixinClassName = "IdeHelper{$classname}";
            $phpdocMixin->appendTag(Tag::createInstance("@mixin {$mixinClassName}", $phpdocMixin));
            $mixinDocComment = $serializer->getDocComment($phpdocMixin);
            // remove blank lines if there's no text
            if (!$phpdocMixin->getText()) {
                $mixinDocComment = preg_replace("/\s\*\s*\n/", '', $mixinDocComment);
            }

            foreach ($phpdoc->getTagsByName('mixin') as $tag) {
                if (Str::startsWith($tag->getContent(), 'IdeHelper')) {
                    $phpdoc->deleteTag($tag);
                }
            }
            $docComment = $serializer->getDocComment($phpdoc);
        }

        if ($this->write) {
            $modelDocComment = $this->write_mixin ? $mixinDocComment : $docComment;
            $filename = $reflection->getFileName();
            $contents = $this->files->get($filename);
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $modelDocComment, $contents);
            } else {
                $replace = "{$modelDocComment}\n";
                $pos = strpos($contents, "final class {$classname}") ?: strpos($contents, "class {$classname}");
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, 0);
                }
            }
            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to ' . $filename);
            }
        }

        $classname = $this->write_mixin ? $mixinClassName : $classname;

        $allowDynamicAttributes = $this->write_mixin ? "#[\AllowDynamicProperties]\n\t" : '';
        $output = "namespace {$namespace}{\n{$docComment}\n\t{$allowDynamicAttributes}{$keyword}class {$classname} ";

        // if (!$this->write_mixin) {
        //     $output .= "extends \Eloquent ";
        //
        //     if ($interfaceNames) {
        //         $interfaces = implode(', \\', $interfaceNames);
        //         $output .= "implements \\{$interfaces} ";
        //     }
        // }

        return $output . "{}\n}\n\n";
    }

    /**
     * Get the parameters and format them correctly
     *
     * @param $method
     * @return array
     * @throws \ReflectionException
     */
    public function getParameters($method)
    {
        //Loop through the default values for parameters, and make the correct output string
        $paramsWithDefault = [];
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramStr = $param->isVariadic() ? '...$' . $param->getName() : '$' . $param->getName();

            if ($paramType = $this->getParamType($method, $param)) {
                $paramStr = $paramType . ' ' . $paramStr;
            }

            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = '[]';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_int($default)) {
                    //$default = $default;
                } elseif ($default instanceof \UnitEnum) {
                    $default = '\\' . get_class($default) . '::' . $default->name;
                } else {
                    $default = "'" . trim($default) . "'";
                }

                $paramStr .= " = $default";
            }

            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * Determine a model classes' collection type.
     *
     * @see http://laravel.com/docs/eloquent-collections#custom-collections
     * @param string $className
     * @return string
     */
    protected function getCollectionClass($className)
    {
        // Return something in the very very unlikely scenario the model doesn't
        // have a newCollection() method.
        if (!method_exists($className, 'newCollection')) {
            return '\Illuminate\Database\Eloquent\Collection';
        }

        /** @var Model $model */
        $model = new $className();
        return '\\' . get_class($model->newCollection());
    }

    /**
     * Determine a model classes' collection type hint.
     *
     * @param string $collectionClassNameInModel
     * @param string $relatedModel
     * @return string
     */
    protected function getCollectionTypeHint(string $collectionClassNameInModel, string $relatedModel): string
    {
        $useGenericsSyntax = $this->laravel['config']->get('ide-helper.use_generics_annotations', true);
        if ($useGenericsSyntax) {
            return $collectionClassNameInModel . '<int, ' . $relatedModel . '>';
        } else {
            return $collectionClassNameInModel . '|' . $relatedModel . '[]';
        }
    }

    /**
     * Returns the return types of relations
     */
    protected function getRelationReturnTypes(): array
    {
        return $this->laravel['config']->get('ide-helper.additional_relation_return_types', []);
    }

    /**
     * @return bool
     */
    protected function hasCamelCaseModelProperties()
    {
        return $this->laravel['config']->get('ide-helper.model_camel_case_properties', false);
    }

    protected function getReturnType(\ReflectionMethod $reflection): ?string
    {
        $type = $this->getReturnTypeFromDocBlock($reflection);

        if ($type) {
            return $type;
        }

        return $this->getReturnTypeFromReflection($reflection);
    }

    /**
     * Get method comment based on it DocBlock comment
     *
     * @param \ReflectionMethod $reflection
     *
     * @return null|string
     */
    protected function getCommentFromDocBlock(\ReflectionMethod $reflection)
    {
        $phpDocContext = (new ContextFactory())->createFromReflector($reflection);
        $context = new Context(
            $phpDocContext->getNamespace(),
            $phpDocContext->getNamespaceAliases()
        );
        $comment = '';
        $phpdoc = new DocBlock($reflection, $context);

        if ($phpdoc->hasTag('comment')) {
            $comment = $phpdoc->getTagsByName('comment')[0]->getContent();
        }

        return $comment;
    }

    /**
     * Get method return type based on it DocBlock comment
     *
     * @param \ReflectionMethod $reflection
     *
     * @return null|string
     */
    protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection, \Reflector $reflectorForContext = null)
    {
        $phpDocContext = (new ContextFactory())->createFromReflector($reflectorForContext ?? $reflection);
        $context = new Context(
            $phpDocContext->getNamespace(),
            $phpDocContext->getNamespaceAliases()
        );
        $type = null;
        $phpdoc = new DocBlock($reflection, $context);

        if ($phpdoc->hasTag('return')) {
            $returnTag = $phpdoc->getTagsByName('return')[0];

            $typeParser = new PhpDocReturnTypeParser($returnTag->getContent(), $context->getNamespaceAliases());
            if ($typeAlias = $typeParser->parse()) {
                return $typeAlias;
            }

            $type = $phpdoc->getTagsByName('return')[0]->getType();
        }

        return $type;
    }

    protected function getReturnTypeFromReflection(\ReflectionMethod $reflection): ?string
    {
        $returnType = $reflection->getReturnType();
        if (!$returnType) {
            return null;
        }

        $types = $this->extractReflectionTypes($returnType);

        $type = implode('|', $types);

        if ($returnType->allowsNull()) {
            $type .= '|null';
        }

        return $type;
    }


    /**
     * Generates methods provided by the SoftDeletes trait
     * @param Model $model
     */
    protected function getSoftDeleteMethods($model)
    {
        $traits = class_uses_recursive($model);
        if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', $traits)) {
            $modelName = $this->getClassNameInDestinationFile($model, get_class($model));
            $builder = $this->getClassNameInDestinationFile($model, \Illuminate\Database\Eloquent\Builder::class);
            $this->setMethod('withTrashed', $builder . '|' . $modelName, []);
            $this->setMethod('withoutTrashed', $builder . '|' . $modelName, []);
            $this->setMethod('onlyTrashed', $builder . '|' . $modelName, []);
        }
    }

    /**
     * @param ReflectionClass $reflection
     * @return string
     */
    protected function getClassKeyword(ReflectionClass $reflection)
    {
        if ($reflection->isFinal()) {
            $keyword = 'final ';
        } elseif ($reflection->isAbstract()) {
            $keyword = 'abstract ';
        } else {
            $keyword = '';
        }

        return $keyword;
    }

    protected function checkForCastableCasts(string $type, array $params = []): string
    {
        if (!class_exists($type) || !interface_exists(Castable::class)) {
            return $type;
        }

        $reflection = new ReflectionClass($type);

        if (!$reflection->implementsInterface(Castable::class)) {
            return $type;
        }

        $cast = call_user_func([$type, 'castUsing'], $params);

        if (is_string($cast) && !is_object($cast)) {
            return $cast;
        }

        $castReflection = new ReflectionObject($cast);

        $methodReflection = $castReflection->getMethod('get');

        return $this->getReturnTypeFromReflection($methodReflection) ??
            $this->getReturnTypeFromDocBlock($methodReflection, $reflection) ??
            $type;
    }

    /**
     * @param  string  $type
     * @return string|null
     * @throws \ReflectionException
     */
    protected function checkForCustomLaravelCasts(string $type): ?string
    {
        if (!class_exists($type) || !interface_exists(CastsAttributes::class)) {
            return $type;
        }

        $reflection = new ReflectionClass($type);

        if (!$reflection->implementsInterface(CastsAttributes::class)) {
            return $type;
        }

        $methodReflection = new \ReflectionMethod($type, 'get');

        $reflectionType = $this->getReturnTypeFromReflection($methodReflection);

        if ($reflectionType === null) {
            $reflectionType = $this->getReturnTypeFromDocBlock($methodReflection);
        }

        if ($reflectionType === 'static' || $reflectionType === '$this') {
            $reflectionType = $type;
        }

        return $reflectionType;
    }

    protected function getTypeInModel(object $model, ?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if (class_exists($type)) {
            $type = $this->getClassNameInDestinationFile($model, $type);
        }

        return $type;
    }

    protected function getClassNameInDestinationFile(object $model, string $className): string
    {
        $reflection = $model instanceof ReflectionClass
            ? $model
            : new ReflectionObject($model);

        $className = trim($className, '\\');
        $writingToExternalFile = !$this->write || $this->write_mixin;
        $classIsNotInExternalFile = $reflection->getName() !== $className;
        $forceFQCN = $this->laravel['config']->get('ide-helper.force_fqn', false);

        if (($writingToExternalFile && $classIsNotInExternalFile) || $forceFQCN) {
            return '\\' . $className;
        }

        $usedClassNames = $this->getUsedClassNames($reflection);
        return $usedClassNames[$className] ?? ('\\' . $className);
    }

    /**
     * @param ReflectionClass $reflection
     * @return string[]
     */
    protected function getUsedClassNames(ReflectionClass $reflection): array
    {
        $namespaceAliases = array_flip((new ContextFactory())->createFromReflector($reflection)->getNamespaceAliases());
        $namespaceAliases[$reflection->getName()] = $reflection->getShortName();

        return $namespaceAliases;
    }

    protected function writeModelExternalBuilderMethods(Model $model): void
    {
        $fullBuilderClass = '\\' . get_class($model->newModelQuery());
        $newBuilderMethods = get_class_methods($fullBuilderClass);
        $originalBuilderMethods = get_class_methods('\Illuminate\Database\Eloquent\Builder');

        // diff the methods between the new builder and original one
        // and create helpers for the ones that are new
        $newMethodsFromNewBuilder = array_diff($newBuilderMethods, $originalBuilderMethods);

        if (!$newMethodsFromNewBuilder) {
            return;
        }

        // after we have retrieved the builder's methods
        // get the class of the builder based on the FQCN option
        $builderClassBasedOnFQCNOption = $this->getClassNameInDestinationFile($model, get_class($model->newModelQuery()));

        foreach ($newMethodsFromNewBuilder as $builderMethod) {
            $reflection = new \ReflectionMethod($fullBuilderClass, $builderMethod);
            $args = $this->getParameters($reflection);

            $this->setMethod(
                $builderMethod,
                $builderClassBasedOnFQCNOption . '|' . $this->getClassNameInDestinationFile($model, get_class($model)),
                $args
            );
        }
    }

    protected function getParamType(\ReflectionMethod $method, \ReflectionParameter $parameter): ?string
    {
        if ($paramType = $parameter->getType()) {
            $types = $this->extractReflectionTypes($paramType);

            $type = implode('|', $types);

            if ($paramType->allowsNull()) {
                if (count($types) == 1) {
                    $type = '?' . $type;
                } else {
                    $type .= '|null';
                }
            }

            return $type;
        }

        $docComment = $method->getDocComment();

        if (!$docComment) {
            return null;
        }

        preg_match(
            '/@param ((?:(?:[\w?|\\\\<>])+(?:\[])?)+)/',
            $docComment ?? '',
            $matches
        );
        $type = $matches[1] ?? '';

        if (strpos($type, '|') !== false) {
            $types = explode('|', $type);

            // if we have more than 2 types
            // we return null as we cannot use unions in php yet
            if (count($types) > 2) {
                return null;
            }

            $hasNull = false;

            foreach ($types as $currentType) {
                if ($currentType === 'null') {
                    $hasNull = true;
                    continue;
                }

                // if we didn't find null assign the current type to the type we want
                $type = $currentType;
            }

            // if we haven't found null type set
            // we return null as we cannot use unions with different types yet
            if (!$hasNull) {
                return null;
            }

            $type = '?' . $type;
        }

        // convert to proper type hint types in php
        $type = str_replace(['boolean', 'integer'], ['bool', 'int'], $type);

        $allowedTypes = [
            'int',
            'bool',
            'string',
            'float',
        ];

        // we replace the ? with an empty string so we can check the actual type
        if (!in_array(str_replace('?', '', $type), $allowedTypes)) {
            return null;
        }

        // if we have a match on index 1
        // then we have found the type of the variable if not we return null
        return $type;
    }

    protected function extractReflectionTypes(ReflectionType $reflection_type)
    {
        if ($reflection_type instanceof ReflectionNamedType) {
            $types[] = $this->getReflectionNamedType($reflection_type);
        } else {
            $types = [];
            foreach ($reflection_type->getTypes() as $named_type) {
                if ($named_type->getName() === 'null') {
                    continue;
                }

                $types[] = $this->getReflectionNamedType($named_type);
            }
        }

        return $types;
    }

    protected function getReflectionNamedType(ReflectionNamedType $paramType): string
    {
        $parameterName = $paramType->getName();
        if (!$paramType->isBuiltin() && $paramType->getName() !== 'static') {
            $parameterName = '\\' . $parameterName;
        }

        return $parameterName;
    }

    /**
     * @param Model $model
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \RuntimeException
     */
    protected function runModelHooks($model): void
    {
        $hooks = $this->laravel['config']->get('ide-helper.model_hooks', []);

        foreach ($hooks as $hook) {
            $hookInstance = $this->laravel->make($hook);

            if (!$hookInstance instanceof ModelHookInterface) {
                throw new \RuntimeException(
                    'Your IDE helper model hook must implement Barryvdh\LaravelIdeHelper\Contracts\ModelHookInterface'
                );
            }

            $hookInstance->run($this, $model);
        }
    }

    /**
     * @param Builder $schema
     * @param string $table
     */
    protected function setForeignKeys($schema, $table)
    {
        foreach ($schema->getForeignKeys($table) as $foreignKeyConstraint) {
            foreach ($foreignKeyConstraint['columns'] as $columnName) {
                $this->foreignKeyConstraintsColumns[] = $columnName;
            }
        }
    }
}
