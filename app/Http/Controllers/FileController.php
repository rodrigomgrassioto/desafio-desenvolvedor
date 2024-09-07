<?php

namespace App\Http\Controllers;

use App\Http\Requests\FileRequest;
use App\Imports\FileImport;
use App\Jobs\ProcessFileImport;
use App\Models\FileContent;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel;


class FileController extends Controller
{
    protected $excel;

    public function __construct(Excel $excel)
    {
        $this->excel = $excel;
    }

    public function upload(FileRequest $request)
    {
        $file = $request->file('file');
        $this->_detectFileEncoding($file);
        $fileName = $file->getClientOriginalName();

        // Verificar se o arquivo já foi enviado
        if (Upload::where('file_name', $fileName)->exists())
            return response()->json(['message' => 'Arquivo enviado anteriormente.'], 400);

        // Converte o arquivo para UTF-8
        $path = $file->getRealPath();
        $utf8FilePath = $this->convertToUtf8($file);

        // Salvar o arquivo
        $filePath = $file->store('files');

        // Criar registro de upload
        $upload = Upload::create([
            'file_name' => $fileName,
            'uploaded_at' => now()
        ]);

        // Importar e salvar o conteúdo do arquivo
        $this->excel->import(new FileImport($upload->id), $utf8FilePath); // uso total memória.

        // Disparar o job para processar o arquivo
//        ProcessFileImport::dispatch($file, $upload->id);

        return response()
            ->json(['message' => 'Arquivo carregado. Será processado em fila. Acompanhe o resultado no logs.']);
    }

    public function uploadHistory(Request $request)
    {
        $query = Upload::query();

        if ($request->has('file_name')) {
            $query->where('file_name', $request->input('file_name'));
        }

        if ($request->has('date')) {
            $query->whereDate('uploaded_at', $request->input('date'));
        }

        $uploads = $query->get();
        return response()->json($uploads);
    }

    public function searchContent(Request $request)
    {
        $query = FileContent::query();

        if ($request->has('tckr_symb')) {
            $query->where('tckr_symb', $request->input('tckr_symb'));
        }

        if ($request->has('rpt_dt')) {
            $query->whereDate('rpt_dt', $request->input('rpt_dt'));
        }

        $contents = $query->paginate(15);
        return response()->json($contents);
    }

    private function convertToUtf8($filePath)
    {
        $utf8FilePath = $filePath . '.utf8.csv';

        // Abre o arquivo original
        $originalFile = fopen($filePath, 'r');
        // Abre o novo arquivo para escrita em UTF-8
        $utf8File = fopen($utf8FilePath, 'w');

        while (($line = fgets($originalFile)) !== false) {
            // Converte a linha para UTF-8
            $utf8Line = mb_convert_encoding($line, 'UTF-8', 'auto');
            fwrite($utf8File, $utf8Line);
        }

        fclose($originalFile);
        fclose($utf8File);

        return $utf8FilePath;
    }

    function _detectFileEncoding($filepath) {
        // VALIDATE $filepath !!!
        $output = array();
        exec('file -i ' . $filepath, $output);
        if (isset($output[0])){
            $ex = explode('charset=', $output[0]);
            dd(isset($ex[1]) ? $ex[1] : null);
        }
        return null;
    }
}
