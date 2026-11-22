package com.baogong.helper;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * V3 消息推送（客户端侧）。
 *
 * 说明：现有 PHP 报工系统是纯内网请求-响应式，本身没有推送通道，也不适合接厂商推送。
 * 因此这里实现的是「前台轮询 + 系统通知」的等价方案，客户端已就绪，需服务端配合提供接口：
 *
 *   GET <服务器地址>/push.php?since=<上次最大时间戳，毫秒>
 *   返回 200 + JSON 数组：[{"ts":1730000000000,"title":"报工提醒","text":"..."}]
 *   无新消息返回 []。接口需校验 bg_session Cookie，避免越权读取。
 *
 * 只在 App 前台（onResume ~ onPause）轮询，不常驻后台、不额外申请后台权限。
 * 服务端尚未实现该接口时，请求失败会被静默跳过，不影响其他功能。
 */
public final class PushPoller {

    public interface Sink {
        void onMessage(String title, String text);
    }

    private static final String TAG = "PushPoller";
    private static final long INTERVAL_SECONDS = 60L;
    private static final Handler MAIN = new Handler(Looper.getMainLooper());

    private static ScheduledExecutorService scheduler;
    private static long since = 0L;

    private PushPoller() {
    }

    public static synchronized void start(Context context, String serverUrl, Sink sink) {
        stop();
        if (serverUrl == null || serverUrl.isEmpty()) {
            return;
        }
        final String endpoint = serverUrl.endsWith("/")
                ? serverUrl + "push.php" : serverUrl + "/push.php";
        final Context app = context.getApplicationContext();
        scheduler = Executors.newSingleThreadScheduledExecutor();
        scheduler.scheduleWithFixedDelay(
                () -> poll(app, endpoint, sink), 5, INTERVAL_SECONDS, TimeUnit.SECONDS);
    }

    public static synchronized void stop() {
        if (scheduler != null) {
            scheduler.shutdownNow();
            scheduler = null;
        }
    }

    private static void poll(Context app, String endpoint, Sink sink) {
        HttpURLConnection conn = null;
        try {
            conn = (HttpURLConnection) new URL(endpoint + "?since=" + since).openConnection();
            conn.setConnectTimeout(8000);
            conn.setReadTimeout(8000);
            conn.setRequestProperty("Accept", "application/json");
            if (conn.getResponseCode() != HttpURLConnection.HTTP_OK) {
                return;
            }
            String body = readAll(conn.getInputStream());
            JSONArray array = new JSONArray(body);
            for (int i = 0; i < array.length(); i++) {
                JSONObject item = array.getJSONObject(i);
                long ts = item.optLong("ts", 0L);
                final String title = item.optString("title", "报工助手");
                final String text = item.optString("text", "");
                if (ts > since) {
                    since = ts;
                }
                MAIN.post(() -> {
                    if (sink != null) {
                        sink.onMessage(title, text);
                    }
                    NotificationHelper.show(app, NotificationHelper.CH_PUSH,
                            NotificationHelper.nextId(), title, text, false, 0, 0);
                });
            }
        } catch (Exception e) {
            // 服务端未部署 push.php、返回非 JSON、网络不可达都属正常，静默跳过
            Log.d(TAG, "轮询跳过: " + e.getMessage());
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }

    private static String readAll(InputStream in) throws Exception {
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        byte[] buf = new byte[4096];
        int n;
        while ((n = in.read(buf)) != -1) {
            bos.write(buf, 0, n);
        }
        return new String(bos.toByteArray(), StandardCharsets.UTF_8);
    }
}
