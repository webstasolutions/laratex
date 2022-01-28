<?php declare(strict_types=1);

namespace Websta\LaraTeX;

use Websta\LaraTeX\LaratexException;
use Websta\LaraTeX\LaratexPdfWasGenerated;
use Websta\LaraTeX\LaratexPdfFailed;
use Websta\LaraTeX\ViewNotFoundException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class LaraTeX
{
    /**
     * Stub view file path
     *
     * @var string|null
     */
    private ?string $stubPath;

    /**
     * Data to pass to the stub
     *
     * @var array
     */
    private array $data;

    /**
     * Rendered tex file
     *
     * @var string
     */
    private string $renderedTex;

    /**
     * If it's a raw tex or a view file
     * @var boolean
     */
    private bool $isRaw = false;

    /**
     * Metadata of the generated pdf
     * @var mixed
     */
    private mixed $metadata;

    /**
     * File Name inside Zip
     *
     * @var string
     */
    private string $nameInsideZip;

    /**
     * @var string
     */
    protected string $binPath;

    /**
     * @var string
     */
    protected string $tempPath;

    /**
     * Construct the instance
     *
     * @param string|null $stubPath
     * @param mixed|null $metadata
     */
    public function __construct(string $stubPath = null, mixed $metadata = null)
    {
        $this->binPath = config('laratex.binPath');
        $this->tempPath = config('laratex.tempPath');
        if ($stubPath instanceof RawTex) {
            $this->isRaw = true;
            $this->renderedTex = $stubPath->getTex();
        } else {
            $this->stubPath = $stubPath;
        }
        $this->metadata = $metadata;
    }

    /**
     * Set name inside zip file
     *
     * @param string $nameInsideZip
     *
     * @return LaraTeX
     */
    public function setName(string $nameInsideZip): LaraTeX
    {
        $this->nameInsideZip = basename($nameInsideZip);
        return $this;
    }

    /**
     * Get name inside zip file
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->nameInsideZip;
    }

    /**
     * Set the with data
     *
     * @param array $data
     *
     * @return LaraTeX
     */
    public function with(array $data): LaraTeX
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Dry run
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     * @throws \Websta\LaraTeX\ViewNotFoundException
     */
    public function dryRun():  \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->isRaw = true;
        $process = new Process(["which", "pdflatex"]);
        $process->run();

        // if (!$process->isSuccessful()) {
        //
        //     throw new LaratexException($process->getOutput());
        // }

        $this->renderedTex = File::get(dirname(__FILE__) . '/dryrun.tex');
        return $this->download('dryrun.pdf');
    }

    /**
     * Render the stub with data
     *
     * @return string
     * @throws ViewNotFoundException
     */
    public function render(): string
    {
        if (!empty($this->renderedTex)) {
            return $this->renderedTex;
        }

        if (!view()->exists($this->stubPath)) {
            throw new ViewNotFoundException('View ' . $this->stubPath . ' not found.');
        }

        $this->renderedTex = view($this->stubPath, $this->data)->render();

        return $this->renderedTex;
    }

    /**
     * Save generated PDF
     *
     * @param string $location
     *
     * @return boolean
     * @throws ViewNotFoundException
     */
    public function savePdf(string $location): bool
    {
        $this->render();
        $pdfPath = $this->generate();
        $fileMoved = File::move($pdfPath, $location);

        LaratexPdfWasGenerated::dispatch($location, 'savepdf', $this->metadata);

        return $fileMoved;
    }

    /**
     * Download file as a response
     *
     * @param string|null $fileName
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     * @throws \Websta\LaraTeX\ViewNotFoundException
     */
    public function download(string $fileName = null):  \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if (!$this->isRaw) {
            $this->render();
        }

        $pdfPath = $this->generate();
        if (!$fileName) {
            $fileName = basename($pdfPath);
        }

        LaratexPdfWasGenerated::dispatch($fileName, 'download', $this->metadata);

        return \Response::download($pdfPath, $fileName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get the file as a inline response
     *
     * @param string|null $fileName
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     * @throws \Websta\LaraTeX\ViewNotFoundException
     */
    public function inline(string $fileName = null):  \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if (!$this->isRaw) {
            $this->render();
        }

        $pdfPath = $this->generate();
        if (!$fileName) {
            $fileName = basename($pdfPath);
        }

        LaratexPdfWasGenerated::dispatch($fileName, 'inline', $this->metadata);

        return \Response::file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"'
        ]);
    }

    /**
     * Get the content of the file
     *
     * @param string $type
     * @return false|Illuminate\Http\Response|string
     * @throws ViewNotFoundException
     */
    public function content(string $type = 'raw'): bool|string|Illuminate\Http\Response
    {
        $fileName = '';
        if ($type == 'raw' || $type == 'base64') {
            if (!$this->isRaw) {
                $this->render();
            }

            $pdfPath = $this->generate();
            $fileName = basename($pdfPath);

            LaratexPdfWasGenerated::dispatch($fileName, 'content', $this->metadata);

            $pdfContent = null;
            if ($type == 'raw') {
                $pdfContent = file_get_contents($pdfPath);
            } elseif ($type == 'base64') {
                $pdfContent = chunk_split(base64_encode(file_get_contents($pdfPath)));
            }

            return $pdfContent;
        } else {
            LaratexPdfFailed::dispatch($fileName, 'content', 'Wrong type set');
            return response()->json(['message' => 'Wrong type set. Use raw or base64.'], 400);
        }
    }

    /**
     * Generate the PDF
     *
     * @return string
     * @throws \Websta\LaraTeX\LaratexException
     */
    private function generate(): string
    {
        $fileName = Str::random(10);
        $basetmpfname = tempnam(storage_path($this->tempPath), $fileName);
        $tmpfname = preg_replace('/\\.[^.\\s]{3,4}$/', '', $basetmpfname);
        rename($basetmpfname, $tmpfname);
        $tmpDir = storage_path($this->tempPath);
        chmod($tmpfname, 0755);

        File::put($tmpfname, $this->renderedTex);

        $program    = $this->binPath ? $this->binPath : 'pdflatex';
        $cmd        = [$program, '-output-directory', $tmpDir, $tmpfname];

        $process    = new Process($cmd);
        $process->run();
        if (!$process->isSuccessful()) {
            LaratexPdfFailed::dispatch($fileName, 'download', $this->metadata);
            $this->parseError($tmpfname, $process);
        }

        $this->teardown($tmpfname);

        register_shutdown_function(function () use ($tmpfname) {
            if (File::exists($tmpfname . '.pdf')) {
                File::delete($tmpfname . '.pdf');
            }
        });

        return $tmpfname . '.pdf';
    }

    /**
     * Teardown secondary files
     *
     * @param string $tmpfname
     *
     * @return void
     */
    private function teardown(string $tmpfname): void
    {
        if (File::exists($tmpfname)) {
            File::delete($tmpfname);
        }
        if (File::exists($tmpfname . '.aux')) {
            File::delete($tmpfname . '.aux');
        }
        if (File::exists($tmpfname . '.log')) {
            File::delete($tmpfname . '.log');
        }
        if (File::exists($tmpfname . '.out')) {
            File::delete($tmpfname . '.out');
        }

    }

    /**
     * Throw error from log file
     *
     * @param string $tmpfname
     * @param $process
     * @throws LaratexException
     */
    private function parseError(string $tmpfname, $process): void
    {

        $logFile = $tmpfname . 'log';

        if (!File::exists($logFile)) {
            throw new LaratexException($process->getOutput());
        }

        $error = File::get($logFile);
        throw new LaratexException($error);
    }

    /**
     * Encodes speical characters inside of a HTML String
     *
     * @param string $HTMLString
     * @param int $ENT
     * @return array|string|null
     */
    private function htmlEntitiesFix(string $HTMLString, int $ENT): array|string|null
    {
        $Matches = array();
        $Separator = '###UNIQUEHTMLTAG###';

        preg_match_all(":</{0,1}[a-z]+[^>]*>:i", $HTMLString, $Matches);

        $Temp = preg_replace(":</{0,1}[a-z]+[^>]*>:i", $Separator, $HTMLString);
        $Temp = explode($Separator, $Temp);

        for ($i = 0; $i < count($Temp); $i++)
            $Temp[$i] = htmlentities($Temp[$i], $ENT, 'UTF-8', false);

        $Temp = join($Separator, $Temp);

        for ($i = 0; $i < count($Matches[0]); $i++)
            $Temp = preg_replace(":$Separator:", $Matches[0][$i], $Temp, 1);

        return $Temp;
    }

    /**
     * Convert HTML String to LaTeX String
     *
     * @param string $Input
     * @param array|null $Override
     * @return string
     */
    public function convertHtmlToLatex(string $Input, array $Override = NULL): string
    {
        $Input = $this->htmlEntitiesFix($Input, ENT_QUOTES | ENT_HTML401);

        $ReplaceDictionary = array(
            array('tag' => 'p', 'extract' => 'value', 'replace' => '$1 \newline '),
            array('tag' => 'b', 'extract' => 'value', 'replace' => '\textbf{$1}'),
            array('tag' => 'strong', 'extract' => 'value', 'replace' => '\textbf{$1}'),
            array('tag' => 'i', 'extract' => 'value', 'replace' => '\textit{$1}'),
            array('tag' => 'em', 'extract' => 'value', 'replace' => '\textit{$1}'),
            array('tag' => 'u', 'extract' => 'value', 'replace' => '\underline{$1}'),
            array('tag' => 'ins', 'extract' => 'value', 'replace' => '\underline{$1}'),
            array('tag' => 'br', 'extract' => 'value', 'replace' => '\newline '),
            array('tag' => 'sup', 'extract' => 'value', 'replace' => '\textsuperscript{$1}'),
            array('tag' => 'sub', 'extract' => 'value', 'replace' => '\textsubscript{$1}'),
            array('tag' => 'h1', 'extract' => 'value', 'replace' => '\section{$1}'),
            array('tag' => 'h2', 'extract' => 'value', 'replace' => '\subsection{$1}'),
            array('tag' => 'h3', 'extract' => 'value', 'replace' => '\subsubsection{$1}'),
            array('tag' => 'h4', 'extract' => 'value', 'replace' => '\paragraph{$1} \mbox{} \\\\'),
            array('tag' => 'h5', 'extract' => 'value', 'replace' => '\subparagraph{$1} \mbox{} \\\\'),
            array('tag' => 'h6', 'extract' => 'value', 'replace' => '\subparagraph{$1} \mbox{} \\\\'),
            array('tag' => 'li', 'extract' => 'value', 'replace' => '\item $1'),
            array('tag' => 'ul', 'extract' => 'value', 'replace' => '\begin{itemize}$1\end{itemize}'),
            array('tag' => 'ol', 'extract' => 'value', 'replace' => '\begin{enumerate}$1\end{enumerate}'),
            array('tag' => 'img', 'extract' => 'src', 'replace' => '\includegraphics[scale=1]{$1}'),
        );

        if (isset($Override)) {
            foreach ($Override as $OverrideArray) {
                $FindExistingTag = array_search($OverrideArray['tag'], array_column($ReplaceDictionary, 'tag'));
                if ($FindExistingTag !== false) {
                    $ReplaceDictionary[$FindExistingTag] = $OverrideArray;
                } else {
                    array_push($ReplaceDictionary, $OverrideArray);
                }
            }
        }

        libxml_use_internal_errors(true);
        $Dom = new \DOMDocument();
        $Dom->loadHTML($Input);

        $AllTags = $Dom->getElementsByTagName('*');
        $AllTagsLength = $AllTags->length;

        for ($i = $AllTagsLength - 1; $i > -1; $i--) {
            $CurrentTag = $AllTags->item($i);
            $CurrentReplaceItem = array_search($CurrentTag->nodeName, array_column($ReplaceDictionary, 'tag'));

            if ($CurrentReplaceItem !== false) {
                $CurrentReplace = $ReplaceDictionary[$CurrentReplaceItem];

                switch ($CurrentReplace['extract']) {
                    case 'value':
                        $ExtractValue = $CurrentTag->nodeValue;
                        break;
                    case 'src':
                        $ExtractValue = $CurrentTag->getAttribute('src');
                        break;
                    default:
                        $ExtractValue = "";
                }

                $NewNode = $Dom->createElement('div', str_replace('$1', $ExtractValue, $CurrentReplace['replace']));
                $CurrentTag->parentNode->replaceChild($NewNode, $CurrentTag);
            }
        }

        return html_entity_decode(strip_tags($Dom->saveHTML()));
    }
}
