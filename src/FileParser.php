<?php


namespace Skyronic\Cookie;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

class FileParser
{

    /**
     * Metadata that is in JSON form at the top of the
     * boilerplate file
     *
     * @var array
     */
    protected $meta;

    /**
     * Raw content of the file
     *
     * @var string
     */
    protected $raw_content;

    /**
     * Evaluated content
     *
     * @var string
     */
    protected $eval_content;

    /**
     * The basename (foo for foo.boilerplate.txt) )
     * @var string
     */
    protected $baseName;

    /**
     * Path of the input file
     *
     * @var string
     */
    protected $path;

    public function __construct($path)
    {
        $this->path = $path;
        $this->raw_content = file_get_contents($path);
        $this->firstPassParse();
    }

    /**
     * Does a first parse and extracts the meta.
     * @throws CookieException
     */
    protected function firstPassParse () {
        $this->baseName = basename($this->path, ".boilerplate.txt");
        $this->meta = $this->getMeta($this->raw_content);
    }

    /**
     * Extract meta from given content
     * @param $content
     * @return mixed
     * @throws CookieException
     */
    protected function getMeta ($content) {
        $meta =  json_decode($this->getHeader($content), true);

        if(json_last_error() !== JSON_ERROR_NONE) {
            throw new CookieException("JSON Exception: [$this->baseName] ".json_last_error_msg());
        }

        return $meta;
    }

    /**
     * @param $text
     * @return mixed
     */
    protected function getHeader ($text) {
        $parts = explode("\n---\n", $text);
        return $parts[0];
    }

    /**
     * Gets the name
     */
    public function getName () {
        return $this->meta['name'];
    }

    /**
     * Gets the base name
     *
     * @return string
     */
    public function getBasename () {
        return $this->baseName;
    }

    public function getParams () {
        return $this->meta['params'];
    }

    /**
     * Evaluates the template with the parameters
     *
     * @param $params
     */
    public function render($params) {
        $params = $this->cleanParams ($params);
        $this->evaluateTemplate($params);

        // get new meta
        $this->meta = $this->getMeta($this->eval_content);
    }

    protected function cleanParams ($input) {
        $result = [];
        $params = $this->getParams();

        // add some extra ones
        $params['name'] = 'required';

        foreach ($params as $key => $type) {
            if ($type === 'flag') {
                if (isset($input[$key])) {
                    $result[$key] = $input[$key];
                }
                else {
                    $result[$key] = false;
                }
            }
            else if ($type === 'optional') {
                if (isset($input[$key])) {
                    $result[$key] = $input[$key];
                }
                else {
                    $result[$key] = false;
                }
            }
            else if ($type === 'required') {
                if (!isset($input[$key])) {
                    throw new CookieException("Needs argument [ $key ]");
                }
                $result[$key] = $input[$key];
            }
            else {
                // type is the default value
                if(isset($input[$key])) {
                    $result[$key] = $input[$key];
                }
                else {
                    $result[$key] = $type;
                }
            }


        }

        return $result;
    }

    /**
     * Takes the entire boilerplate file and
     * renders it using blade to
     *
     * @param $params
     */
    protected function evaluateTemplate ($params) {
        $contents = $this->raw_content;

        $contents = str_replace("<?php", "START_PHP", $contents);
        $contents = str_replace("?>", "END_PHP", $contents);
        $contents = str_replace("<?", "START_SHORT", $contents);

        $result = $this->bladeCompile($contents, $params);

        $result = str_replace("START_PHP", "<?php", $result);
        $result = str_replace("END_PHP", "?>", $result);
        $result = str_replace("START_SHORT", "<?", $result);

        $this->eval_content = $result;
    }

    /**
     * Do the actual blade compilation
     *
     * @param $value
     * @param $args
     * @return string
     * @throws \Exception
     */
    protected function bladeCompile ($value, $args) {
        $fs = new Filesystem();
        $x = new BladeCompiler ($fs, sys_get_temp_dir());
        $generated = $x->compileString($value);

        ob_start() and extract($args, EXTR_SKIP);

        try
        {
            eval('?>'.$generated);
        }
        catch (\Exception $e)
        {
            ob_get_clean(); throw $e;
        }

        $content = ob_get_clean();

        return $content;
    }

    public function getOutPath () {
        return $this->meta['out'];
    }

    public function getContents () {
        return $this->getBody($this->eval_content);
    }

    protected function getBody ($text) {
        $parts = explode("\n---\n", $text);
        return $parts[1];
    }
}