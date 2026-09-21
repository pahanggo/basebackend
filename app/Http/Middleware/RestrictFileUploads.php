<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RestrictFileUploads
{
    /**
     * Extensions allowed anywhere in the application, each mapped to the
     * real (content-sniffed) MIME type(s) it must actually match. Sniffing
     * is done from file content (finfo), not the client-supplied extension
     * or Content-Type header, so an extension/content mismatch is rejected
     * even if the extension itself is on the whitelist.
     */
    protected array $allowedExtensionMimes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xls' => ['application/vnd.ms-excel'],
        'csv' => ['text/csv', 'text/plain'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'zip' => ['application/zip'],
        'rar' => ['application/x-rar', 'application/x-rar-compressed', 'application/vnd.rar'],
    ];

    /**
     * Extensions that must never appear anywhere in the filename, even as an
     * intermediate segment (e.g. "shell.php.jpg"). Some misconfigured web
     * servers will still execute these as scripts even though the final
     * extension looks harmless.
     */
    protected array $dangerousSegments = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'dll', 'so',
        'js', 'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asa', 'cer', 'htaccess', 'htpasswd',
        'svg', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'jar', 'war', 'msi', 'vbs', 'ps1',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->flattenFiles($request->allFiles()) as $inputName => $file) {
            if (! $file->isValid()) {
                continue;
            }

            $originalName = (string) $file->getClientOriginalName();
            $lastExtension = strtolower((string) $file->getClientOriginalExtension());
            $mime = $file->getMimeType();

            // Real-world filenames routinely contain dots in the base name
            // ("CamScanner 21-02-2026 11.39.pdf", "Photo at 11.26.48 PM.jpeg"),
            // so we cannot require every segment to be a whitelisted extension.
            // Instead, reject if any intermediate segment is a script/executable
            // extension, which blocks double extension tricks such as
            // "shell.php.jpg" or "shell.phtml.jpg".
            $segments = explode('.', $originalName);
            array_shift($segments); // drop the base filename
            $hasDangerousSegment = collect($segments)
                ->contains(fn ($segment) => in_array(strtolower(trim($segment)), $this->dangerousSegments, true));

            $expectedMimes = $this->allowedExtensionMimes[$lastExtension] ?? null;

            if ($segments === []
                || $lastExtension !== strtolower((string) end($segments))
                || $hasDangerousSegment
                || $expectedMimes === null
                || ! in_array($mime, $expectedMimes, true)
            ) {
                Log::channel('file_upload_rejections')->warning('Rejected file upload: disallowed extension/MIME', [
                    'original_name' => $originalName,
                    'extension' => $lastExtension,
                    'sniffed_mime' => $mime,
                    'ip' => $request->ip(),
                    'user_id' => $request->user()?->getAuthIdentifier(),
                    'route' => $request->path(),
                ]);

                // Behaves like a failed validation rule: JSON/ajax callers get a 422 with an
                // `errors` map keyed by the input, regular forms are redirected back with the
                // message attached to the offending field instead of a bare error page.
                throw ValidationException::withMessages([
                    $inputName => __('Jenis fail ini tidak dibenarkan untuk dimuat naik.'),
                ]);
            }
        }

        return $next($request);
    }

    /**
     * Recursively flatten the (possibly nested) array returned by $request->allFiles()
     * into [dot.notation.input.name => UploadedFile].
     *
     * @return array<string, UploadedFile>
     */
    protected function flattenFiles(array $files, string $prefix = ''): array
    {
        $flat = [];

        foreach ($files as $key => $file) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if ($file instanceof UploadedFile) {
                $flat[$name] = $file;
            } elseif (is_array($file)) {
                $flat += $this->flattenFiles($file, $name);
            }
        }

        return $flat;
    }
}
