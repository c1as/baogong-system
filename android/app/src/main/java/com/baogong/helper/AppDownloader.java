package com.baogong.helper;

import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Context;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.MediaStore;
import android.webkit.URLUtil;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * V3 App 内下载器，替代 V1 的系统 DownloadManager。
 *
 * 为什么要替换：文档第 10 节指出，内嵌 WireGuard 若启用 IncludedApplications，
 * 系统 DownloadManager 不在隧道名单内，下载必然失败。改为 App 内自己发请求后，
 * 流量随本 App 一起走 VPN 隧道，隧道内下载可用。
 *
 * 特性：携带 WebView 的 bg_session Cookie 与原 User-Agent、按 Content-Disposition 取名、
 * 进度通知、Android 10+ 写 MediaStore Downloads（无需权限）、Android 9- 写公共下载目录。
 */
public final class AppDownloader {

    public interface Callback {
        void onResult(boolean ok, String message);
    }

    private static final ExecutorService POOL = Executors.newSingleThreadExecutor();
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final int BUFFER = 16 * 1024;

    private AppDownloader() {
    }

    public static void start(Context context, String url, String userAgent,
                             String contentDisposition, String mime, String cookie,
                             Callback callback) {
        Context app = context.getApplicationContext();
        POOL.execute(() -> doDownload(app, url, userAgent, contentDisposition, mime, cookie, callback));
    }

    private static void doDownload(Context app, String url, String userAgent, String disposition,
                                   String mime, String cookie, Callback callback) {
        final int notifId = NotificationHelper.nextId();
        String guessed = URLUtil.guessFileName(url, disposition, mime);
        final String fileName = (guessed == null || guessed.isEmpty())
                ? "download_" + System.currentTimeMillis() : guessed;
        final String type = (mime == null || mime.isEmpty()) ? "application/octet-stream" : mime;

        HttpURLConnection conn = null;
        InputStream in = null;
        OutputStream out = null;
        Uri mediaUri = null;
        File legacyFile = null;
        boolean ok = false;
        String message = "";

        try {
            NotificationHelper.show(app, NotificationHelper.CH_DOWNLOAD, notifId,
                    "正在下载", fileName, true, 0, 0);

            conn = (HttpURLConnection) new URL(url).openConnection();
            conn.setInstanceFollowRedirects(true);
            conn.setConnectTimeout(15000);
            conn.setReadTimeout(30000);
            if (userAgent != null && !userAgent.isEmpty()) {
                conn.setRequestProperty("User-Agent", userAgent);
            }
            if (cookie != null && !cookie.isEmpty()) {
                conn.setRequestProperty("Cookie", cookie);
            }
            conn.connect();

            int code = conn.getResponseCode();
            if (code < 200 || code >= 300) {
                throw new IllegalStateException("服务器返回 HTTP " + code);
            }

            ContentResolver resolver = app.getContentResolver();
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ContentValues values = new ContentValues();
                values.put(MediaStore.Downloads.DISPLAY_NAME, fileName);
                values.put(MediaStore.Downloads.MIME_TYPE, type);
                values.put(MediaStore.Downloads.IS_PENDING, 1);
                mediaUri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values);
                if (mediaUri == null) {
                    throw new IllegalStateException("无法创建下载文件");
                }
                out = resolver.openOutputStream(mediaUri);
            } else {
                File dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                if (dir != null && !dir.exists() && !dir.mkdirs()) {
                    throw new IllegalStateException("无法创建下载目录");
                }
                legacyFile = new File(dir, fileName);
                out = new FileOutputStream(legacyFile);
            }
            if (out == null) {
                throw new IllegalStateException("无法打开输出流");
            }

            in = conn.getInputStream();
            long total = conn.getContentLength();
            long done = 0;
            long lastNotify = 0;
            byte[] buf = new byte[BUFFER];
            int n;
            while ((n = in.read(buf)) != -1) {
                out.write(buf, 0, n);
                done += n;
                long now = System.currentTimeMillis();
                if (now - lastNotify > 400) {
                    lastNotify = now;
                    if (total > 0) {
                        int percent = (int) (done * 100 / total);
                        NotificationHelper.show(app, NotificationHelper.CH_DOWNLOAD, notifId,
                                "正在下载 " + percent + "%", fileName, true, percent, 100);
                    }
                }
            }
            out.flush();

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && mediaUri != null) {
                ContentValues doneValues = new ContentValues();
                doneValues.put(MediaStore.Downloads.IS_PENDING, 0);
                resolver.update(mediaUri, doneValues, null, null);
            }

            ok = true;
            message = "已保存到「下载」目录：" + fileName;
            NotificationHelper.finish(app, notifId, NotificationHelper.CH_DOWNLOAD,
                    "下载完成", fileName);
        } catch (Exception e) {
            message = "下载失败：" + e.getMessage();
            if (mediaUri != null) {
                try {
                    app.getContentResolver().delete(mediaUri, null, null);
                } catch (Exception ignored) {
                }
            }
            if (legacyFile != null) {
                //noinspection ResultOfMethodCallIgnored
                legacyFile.delete();
            }
            NotificationHelper.finish(app, notifId, NotificationHelper.CH_DOWNLOAD,
                    "下载失败", fileName);
        } finally {
            closeQuietly(out);
            closeQuietly(in);
            if (conn != null) {
                conn.disconnect();
            }
            final boolean result = ok;
            final String text = message;
            if (callback != null) {
                MAIN.post(() -> callback.onResult(result, text));
            }
        }
    }

    private static void closeQuietly(java.io.Closeable c) {
        if (c != null) {
            try {
                c.close();
            } catch (Exception ignored) {
            }
        }
    }
}
