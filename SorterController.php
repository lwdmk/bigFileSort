<?php
namespace app\commands;

use yii\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;

class SorterController extends Controller
{
    /**
     * @var string
     */
    public $basePath = '';

    /**
     * @var string
     */
    public $tempDir = 'sorting';

    /**
     * @inheritdoc
     */
    public $defaultAction = 'index';

    /***
     * @param \yii\base\Action $action
     * @return bool
     */
    public function beforeAction($action)
    {
        ini_set('memory_limit', '128M');

        $this->basePath = \Yii::getAlias('@runtime');
        $this->tempDir = $this->basePath . DIRECTORY_SEPARATOR . $this->tempDir . DIRECTORY_SEPARATOR;

        return parent::beforeAction($action);
    }

    /**
     * Разбиение исходного файла на части и последующая сортировка слиянием
     *
     * @param string $filename
     *
     * @throws Exception
     */
    public function actionIndex($filename = 'source.txt')
    {
        $memoryLimit = $this->returnMemoryLimitsInBytes(ini_get('memory_limit'));

        $sourcePath = $this->basePath . DIRECTORY_SEPARATOR . $filename;
        $destinationFilePath = $this->basePath . DIRECTORY_SEPARATOR . 'sorted_' . $filename;

        if(! file_exists($sourcePath)) {
            $this->stdout('File ' . $sourcePath . ' not found' . PHP_EOL);
            return ExitCode::DATAERR;
        }

        try {
            if (!is_dir($this->tempDir)) {
                mkdir($this->tempDir);
            }

            $sourceFileResource = fopen($sourcePath, 'r');

            $registry = [];
            $inMemoryArray = [];

            $fileCounter = 1;
            $tempFilePath = $this->generateTempPath($fileCounter);

            // разбиваем файл на части, попутно сортируя их
            while ($row = fgets($sourceFileResource)) {
                $inMemoryArray[] = (int)trim($row, PHP_EOL);
                if (memory_get_usage() >= ($memoryLimit / 2)) {
                    $this->releaseMemory($registry, $inMemoryArray, $fileCounter, $tempFilePath);
                    $inMemoryArray = [];
                    $tempFilePath = $this->generateTempPath($fileCounter);
                }
            }

            $this->releaseMemory($registry, $inMemoryArray, $fileCounter, $tempFilePath);
            fclose($sourceFileResource);

            // мерджим файлы в один
            while (count($registry) > 1) {
                $filePath1 = array_pop($registry);
                $filePath2 = array_pop($registry);
                $mergedTempFilePath = $this->tempDir . 'mergedTemp' . count($registry) . '.txt';

                $fileResource1 = fopen($filePath1, 'r');
                $fileResource2 = fopen($filePath2, 'r');
                $mergedTempFileResource = fopen($mergedTempFilePath, 'a');

                $file1Row = fgets($fileResource1);
                $file2Row = fgets($fileResource2);

                while ($file1Row && $file2Row) {
                    $file1RowValue = (int)trim($file1Row, PHP_EOL);
                    $file2RowValue = (int)trim($file2Row, PHP_EOL);

                    if ($file1RowValue >= $file2RowValue) {
                        fwrite($mergedTempFileResource, $file2RowValue . PHP_EOL);
                        $file2Row = fgets($fileResource2);
                    } else {
                        fwrite($mergedTempFileResource, $file1RowValue . PHP_EOL);
                        $file1Row = fgets($fileResource1);
                    }
                }

                $notEmptyFile = (false === $file1Row) ? $fileResource2 : $fileResource1;
                $emptyFile = (false === $file1Row) ? $fileResource1 : $fileResource2;

                while ($row = fgets($notEmptyFile)) {
                    fwrite($mergedTempFileResource, $row);
                }

                fclose($emptyFile);
                fclose($notEmptyFile);
                fclose($mergedTempFileResource);

                unlink($filePath1);
                unlink($filePath2);

                array_unshift($registry, $mergedTempFilePath);
            }

            $resultFile = array_pop($registry);
            copy($resultFile, $destinationFilePath);
            unlink($resultFile);
        } catch (\Exception $e) {
            $this->stdout($e->getMessage());
            return ExitCode::DATAERR;
        }
        return ExitCode::OK;
    }

    /**
     * Генерация тестового файла
     *
     * @param string $size
     *
     * @return int
     */
    public function actionGenerate($size = '256')
    {
        $path = \Yii::getAlias('@runtime');
        $full = $path . DIRECTORY_SEPARATOR . 'source.txt';

        @unlink($full);

        $file = fopen($full, 'a');

        $bytes = ($size * 1024 * 1024);
        $bytesInFile = 0;

        try {
            while ($bytesInFile < $bytes) {
                $symbol = rand(0, 10000);
                fwrite($file, $symbol . PHP_EOL);
                $stat = fstat($file);
                if(! array_key_exists('size', $stat)) {
                    throw new Exception('Size param not found');
                }
                $bytesInFile = $stat['size'];
            }
        } catch (\Exception $e) {
            $this->stdout($e->getMessage());
            fclose($file);
            return ExitCode::DATAERR;
        }

        fclose($file);
        return ExitCode::OK;
    }

    /**
     * Получение лимита памяти в байтах
     *
     * @param $val
     * @return int|string
     */
    protected function returnMemoryLimitsInBytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int) $val;
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Набор операций, производимый в случае превышения лимита используемой памяти, а так же при достижении конца
     * исходного файла. А именно:
     * - сортировка массива в памяти
     * - сохранение его в файл
     * - освобождение памяти
     * - приращение счетчика файлов
     * - регистрация сохраненного файла в реесте
     * 
     * @param $registry
     * @param $inMemoryArray
     * @param $fileCounter
     * @param $tempFilePath
     */
    protected function releaseMemory(&$registry, &$inMemoryArray, &$fileCounter, $tempFilePath)
    {
        sort($inMemoryArray);
        file_put_contents($tempFilePath, implode(PHP_EOL, $inMemoryArray));
        unset($inMemoryArray);
        $fileCounter ++;
        $registry[] = $tempFilePath;
    }

    /**
     * Генерация имени для временного файла
     *
     * @param $counter
     *
     * @return string
     */
    protected function generateTempPath($counter)
    {
        $fileNameTemplate = 'temp';

        $tempFileName = $fileNameTemplate . $counter. '.txt';
        $tempFilePath = $this->tempDir . $tempFileName;
        @unlink($tempFilePath);

        return $tempFilePath;
    }
}