package com.baogong.helper;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.MediaStore;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;
import android.widget.Toolbar;

import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;

import java.io.File;
import java.io.IOException;
import java.util.Locale;

/**
 * 报工助手主界面（V1 WebView 外壳 + V2/V3 改造）。
 *
 * V1 保留：服务器地址配置、错误页、拍照/选图上传、Cookie 登录态、返回键导航。
 * V2 新增：代码构建 Toolbar、Android 15 边到边显示、仅允许 HTTPS 开关、VPN 与推送菜单入口。
 * V3 新增：离线友好提示页、App 内下载（替代系统 DownloadManager）、网络状态监听、通知渠道。
 *
 * 仍然不预置服务器地址：首次打开由用户自行填写。
 */
public class MainActivity extends Activity {

    private static final String PREFS = "bg_shell";
    private static final String KEY_SERVER = "server_url";
    private static final String KEY_HTTPS_ONLY = "https_only";
    private static final String KEY_PUSH = "push_enabled";

    private static final int MENU_SERVER = 1;
    private static final int MENU_RELOAD = 2;
    private static final int MENU_HTTPS = 3;
    private static final int MENU_VPN = 4;
    private static final int MENU_PUSH = 5;
    private static final int MENU_LOGOUT = 6;
    private static final int MENU_ABOUT = 7;

    private static final int REQ_PICK = 1001;
    private static final int REQ_DL_PERM = 1002;
    private static final int REQ_NOTIF_PERM = 1003;

    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    private WebView webView;
    private View errorView;
    private View offlineView;
    private TextView errorText;
    private SharedPreferences prefs;

    private ValueCallback<Uri[]> fileCallback;
    private Uri cameraUri;
    private File cameraFile;

    private String pendingUrl;
    private String pendingUserAgent;
    private String pendingDisposition;
    private String pendingMime;

    private NetworkMonitor networkMonitor;
    private boolean offlineShowing;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        prefs = getSharedPreferences(PREFS, MODE_PRIVATE);

        NotificationHelper.createChannels(this);
        requestNotificationPermissionIfNeeded();

        // V2 边到边：内容延伸到状态栏/导航栏背后，再由 insets 把内容推回安全区
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        getWindow().setStatusBarColor(Color.TRANSPARENT);
        getWindow().setNavigationBarColor(Color.TRANSPARENT);
        WindowInsetsControllerCompat controller =
                WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView());
        if (controller != null) {
            controller.setAppearanceLightStatusBars(true);
            controller.setAppearanceLightNavigationBars(true);
        }

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptFileSchemeCookies(true);

        // 纯代码构建界面：顶部 Toolbar + 下方内容区（WebView / 错误页 / 离线页叠放）
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(0xFFFFFFFF);

        Toolbar toolbar = new Toolbar(this);
        toolbar.setTitle("报工助手");
        toolbar.setTitleTextColor(0xFF333333);
        toolbar.setBackgroundColor(0xFFFFFFFF);
        root.addView(toolbar, new LinearLayout.LayoutParams(MATCH, dp(56)));
        setActionBar(toolbar);

        FrameLayout content = new FrameLayout(this);
        webView = new WebView(this);
        content.addView(webView, new FrameLayout.LayoutParams(MATCH, MATCH));
        configureWebView();

        errorView = buildErrorView();
        errorView.setVisibility(View.GONE);
        content.addView(errorView, new FrameLayout.LayoutParams(MATCH, MATCH));

        offlineView = buildOfflineView();
        offlineView.setVisibility(View.GONE);
        content.addView(offlineView, new FrameLayout.LayoutParams(MATCH, MATCH));

        root.addView(content, new LinearLayout.LayoutParams(MATCH, 0, 1f));
        setContentView(root);

        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
            return insets;
        });

        networkMonitor = NetworkMonitor.register(this, this::onNetworkChanged);

        String url = prefs.getString(KEY_SERVER, "");
        if (url == null || url.isEmpty()) {
            showServerDialog(false);
        } else {
            webView.loadUrl(url);
        }

        if (pushEnabled()) {
            startPush();
        }
    }

    // ---------------------------------------------------------------------
    // V2 / V3 开关
    // ---------------------------------------------------------------------

    private boolean httpsOnly() {
        return prefs.getBoolean(KEY_HTTPS_ONLY, false);
    }

    private boolean pushEnabled() {
        return prefs.getBoolean(KEY_PUSH, false);
    }

    private void startPush() {
        String url = prefs.getString(KEY_SERVER, "");
        if (url == null || url.isEmpty()) {
            return;
        }
        PushPoller.start(this, url, (title, text) -> {
            // 通知已由 PushPoller 发出，这里预留给页面内提示
        });
    }

    private void requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS},
                    REQ_NOTIF_PERM);
        }
    }

    // ---------------------------------------------------------------------
    // WebView
    // ---------------------------------------------------------------------

    private void configureWebView() {
        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setUseWideViewPort(true);
        s.setLoadWithOverviewMode(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(true);
        s.setJavaScriptCanOpenWindowsAutomatically(false);
        s.setSupportMultipleWindows(false);
        s.setDisplayZoomControls(false);
        s.setBuiltInZoomControls(false);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setTextZoom(100);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);
        }

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String scheme = uri.getScheme() == null ? ""
                        : uri.getScheme().toLowerCase(Locale.ROOT);

                // V2「仅允许 HTTPS」：在客户端拦截明文主文档请求
                if (httpsOnly() && "http".equals(scheme) && request.isForMainFrame()) {
                    showError("已开启「仅允许 HTTPS」\n\n" + uri
                            + "\n\n这是明文 HTTP 地址，已被拦截。\n"
                            + "请在右上角菜单关闭该开关，或在「服务器地址」里改用 https:// 地址。");
                    return true;
                }

                if ("http".equals(scheme) || "https".equals(scheme)
                        || "about".equals(scheme) || "data".equals(scheme)
                        || "blob".equals(scheme)) {
                    return false;
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                } catch (ActivityNotFoundException e) {
                    Toast.makeText(MainActivity.this, "没有可打开该链接的应用",
                            Toast.LENGTH_SHORT).show();
                }
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                errorView.setVisibility(View.GONE);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request,
                                        WebResourceError error) {
                if (request.isForMainFrame()) {
                    showError("无法连接服务器\n\n" + request.getUrl() + "\n"
                            + error.getDescription()
                            + "\n\n请确认手机已连接内网，或检查服务器地址是否正确。");
                }
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                                             FileChooserParams params) {
                if (fileCallback != null) {
                    fileCallback.onReceiveValue(null);
                    fileCallback = null;
                }
                fileCallback = callback;
                try {
                    startActivityForResult(buildFileChooserIntent(params), REQ_PICK);
                    return true;
                } catch (ActivityNotFoundException e) {
                    fileCallback = null;
                    Toast.makeText(MainActivity.this, "没有可用的文件选择器",
                            Toast.LENGTH_SHORT).show();
                    return false;
                }
            }
        });

        // V3：下载改由 App 内完成，流量随本 App 走 VPN 隧道
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            String mime = (mimeType == null || mimeType.isEmpty()) ? null : mimeType;
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q
                    && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE)
                    != PackageManager.PERMISSION_GRANTED) {
                pendingUrl = url;
                pendingUserAgent = userAgent;
                pendingDisposition = contentDisposition;
                pendingMime = mime;
                requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                        REQ_DL_PERM);
                return;
            }
            startDownload(url, userAgent, contentDisposition, mime);
        });
    }

    private void startDownload(String url, String userAgent, String disposition, String mime) {
        String cookie = CookieManager.getInstance().getCookie(url);
        AppDownloader.start(this, url, userAgent, disposition, mime, cookie,
                (ok, message) -> Toast.makeText(MainActivity.this, message,
                        Toast.LENGTH_LONG).show());
    }

    // ---------------------------------------------------------------------
    // 错误页 / 离线页
    // ---------------------------------------------------------------------

    private View buildErrorView() {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.setPadding(dp(28), dp(28), dp(28), dp(28));
        box.setBackgroundColor(0xFFFFFFFF);

        errorText = new TextView(this);
        errorText.setTextColor(0xFF333333);
        errorText.setTextSize(15);
        errorText.setGravity(Gravity.CENTER);
        errorText.setText("无法连接服务器");
        box.addView(errorText);

        Button retry = new Button(this);
        retry.setText("重新加载");
        retry.setOnClickListener(v -> {
            errorView.setVisibility(View.GONE);
            webView.reload();
        });
        LinearLayout.LayoutParams retryLp = new LinearLayout.LayoutParams(WRAP, WRAP);
        retryLp.topMargin = dp(20);
        box.addView(retry, retryLp);

        Button config = new Button(this);
        config.setText("修改服务器地址");
        config.setOnClickListener(v -> showServerDialog(true));
        LinearLayout.LayoutParams configLp = new LinearLayout.LayoutParams(WRAP, WRAP);
        configLp.topMargin = dp(8);
        box.addView(config, configLp);

        return box;
    }

    /** V3 离线友好提示页：断网时不再白屏，而是给出可操作的说明 */
    private View buildOfflineView() {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.setPadding(dp(28), dp(28), dp(28), dp(28));
        box.setBackgroundColor(0xFFFFFFFF);

        TextView title = new TextView(this);
        title.setText("当前没有网络连接");
        title.setTextColor(0xFF333333);
        title.setTextSize(18);
        title.setGravity(Gravity.CENTER);
        box.addView(title);

        TextView desc = new TextView(this);
        desc.setText("报工助手的页面和数据都在服务器上，需要联网才能使用。\n\n"
                + "请检查 Wi-Fi 或移动数据；外网使用时请先连接 VPN 隧道。");
        desc.setTextColor(0xFF666666);
        desc.setTextSize(14);
        desc.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams descLp = new LinearLayout.LayoutParams(WRAP, WRAP);
        descLp.topMargin = dp(14);
        box.addView(desc, descLp);

        Button retry = new Button(this);
        retry.setText("重试");
        retry.setOnClickListener(v -> {
            if (NetworkMonitor.isOnline(MainActivity.this)) {
                offlineShowing = false;
                offlineView.setVisibility(View.GONE);
                webView.reload();
            } else {
                Toast.makeText(MainActivity.this, "仍然没有网络", Toast.LENGTH_SHORT).show();
            }
        });
        LinearLayout.LayoutParams retryLp = new LinearLayout.LayoutParams(WRAP, WRAP);
        retryLp.topMargin = dp(24);
        box.addView(retry, retryLp);

        Button vpn = new Button(this);
        vpn.setText("网络设置（VPN / 组网）");
        vpn.setOnClickListener(v -> startActivity(
                new Intent(MainActivity.this, NetworkSettingsActivity.class)));
        LinearLayout.LayoutParams vpnLp = new LinearLayout.LayoutParams(WRAP, WRAP);
        vpnLp.topMargin = dp(8);
        box.addView(vpn, vpnLp);

        return box;
    }

    private void showError(String message) {
        if (errorText == null || errorView == null) {
            return;
        }
        if (offlineView != null) {
            offlineView.setVisibility(View.GONE);
        }
        offlineShowing = false;
        errorText.setText(message);
        errorView.setVisibility(View.VISIBLE);
        errorView.bringToFront();
    }

    private void onNetworkChanged(boolean online) {
        runOnUiThread(() -> {
            if (online) {
                if (offlineShowing) {
                    offlineShowing = false;
                    offlineView.setVisibility(View.GONE);
                    errorView.setVisibility(View.GONE);
                    webView.reload();
                    Toast.makeText(MainActivity.this, "网络已恢复，正在重新加载",
                            Toast.LENGTH_SHORT).show();
                }
            } else {
                offlineShowing = true;
                errorView.setVisibility(View.GONE);
                offlineView.setVisibility(View.VISIBLE);
                offlineView.bringToFront();
            }
        });
    }

    // ---------------------------------------------------------------------
    // 拍照 / 文件选择（沿用 V1）
    // ---------------------------------------------------------------------

    private Intent buildFileChooserIntent(WebChromeClient.FileChooserParams params) {
        String[] accepts = params.getAcceptTypes();
        String accept = "";
        if (accepts != null) {
            for (String a : accepts) {
                if (a != null && !a.trim().isEmpty()) {
                    accept = a.trim();
                    break;
                }
            }
        }
        boolean wantsImage = accept.isEmpty() || accept.toLowerCase(Locale.ROOT).contains("image");

        Intent content = new Intent(Intent.ACTION_GET_CONTENT);
        content.addCategory(Intent.CATEGORY_OPENABLE);
        content.setType(wantsImage ? "image/*" : "*/*");
        if (params.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) {
            content.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
        }

        Intent chooser = Intent.createChooser(content, "选择图片或文件");
        if (wantsImage) {
            Intent camera = buildCameraIntent();
            if (camera != null) {
                chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{camera});
                chooser.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION
                        | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
            }
        }
        return chooser;
    }

    private Intent buildCameraIntent() {
        try {
            File dir = new File(getCacheDir(), "camera");
            if (!dir.exists() && !dir.mkdirs()) {
                return null;
            }
            cameraFile = File.createTempFile("shot_", ".jpg", dir);
            cameraUri = FileProvider.getUriForFile(this,
                    getPackageName() + ".fileprovider", cameraFile);
            Intent intent = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
            intent.putExtra(MediaStore.EXTRA_OUTPUT, cameraUri);
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION
                    | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
            intent.setClipData(ClipData.newRawUri("", cameraUri));
            return intent;
        } catch (IOException e) {
            cameraUri = null;
            cameraFile = null;
            return null;
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode != REQ_PICK) {
            super.onActivityResult(requestCode, resultCode, data);
            return;
        }
        Uri[] results = null;
        if (resultCode == RESULT_OK) {
            if (data != null && data.getClipData() != null) {
                ClipData clip = data.getClipData();
                results = new Uri[clip.getItemCount()];
                for (int i = 0; i < clip.getItemCount(); i++) {
                    results[i] = clip.getItemAt(i).getUri();
                }
            } else if (data != null && data.getData() != null) {
                results = new Uri[]{data.getData()};
            } else if (cameraUri != null) {
                results = new Uri[]{cameraUri};
            }
        }
        if (results == null && cameraFile != null) {
            //noinspection ResultOfMethodCallIgnored
            cameraFile.delete();
        }
        if (fileCallback != null) {
            fileCallback.onReceiveValue(results);
            fileCallback = null;
        }
        cameraUri = null;
        cameraFile = null;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions,
                                           int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_DL_PERM) {
            if (grantResults.length > 0
                    && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                startDownload(pendingUrl, pendingUserAgent, pendingDisposition, pendingMime);
            } else {
                Toast.makeText(this, "未授予存储权限，无法保存文件", Toast.LENGTH_LONG).show();
            }
            pendingUrl = null;
            pendingUserAgent = null;
            pendingDisposition = null;
            pendingMime = null;
        }
        // REQ_NOTIF_PERM：用户拒绝通知权限时下载 / VPN 仍可用，仅不显示通知
    }

    // ---------------------------------------------------------------------
    // 菜单
    // ---------------------------------------------------------------------

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, MENU_SERVER, 0, "服务器地址");
        menu.add(0, MENU_RELOAD, 1, "重新加载");
        MenuItem https = menu.add(0, MENU_HTTPS, 2,
                httpsOnly() ? "仅允许 HTTPS（开）" : "仅允许 HTTPS（关）");
        https.setCheckable(true);
        https.setChecked(httpsOnly());
        menu.add(0, MENU_VPN, 3, "网络设置（WireGuard / EasyTier）");
        MenuItem push = menu.add(0, MENU_PUSH, 4,
                pushEnabled() ? "消息推送（开）" : "消息推送（关）");
        push.setCheckable(true);
        push.setChecked(pushEnabled());
        menu.add(0, MENU_LOGOUT, 5, "清除登录状态");
        menu.add(0, MENU_ABOUT, 6, "使用说明");
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        int id = item.getItemId();
        if (id == MENU_SERVER) {
            showServerDialog(true);
            return true;
        }
        if (id == MENU_RELOAD) {
            errorView.setVisibility(View.GONE);
            webView.reload();
            return true;
        }
        if (id == MENU_HTTPS) {
            toggleHttpsOnly();
            return true;
        }
        if (id == MENU_VPN) {
            startActivity(new Intent(this, NetworkSettingsActivity.class));
            return true;
        }
        if (id == MENU_PUSH) {
            togglePush();
            return true;
        }
        if (id == MENU_LOGOUT) {
            clearSession();
            return true;
        }
        if (id == MENU_ABOUT) {
            showAbout();
            return true;
        }
        return super.onOptionsItemSelected(item);
    }

    private void toggleHttpsOnly() {
        boolean next = !httpsOnly();
        prefs.edit().putBoolean(KEY_HTTPS_ONLY, next).apply();
        invalidateOptionsMenu();

        String current = prefs.getString(KEY_SERVER, "");
        if (next && current != null && current.toLowerCase(Locale.ROOT).startsWith("http://")) {
            String upgraded = "https://" + current.substring(7);
            prefs.edit().putString(KEY_SERVER, upgraded).apply();
            errorView.setVisibility(View.GONE);
            webView.loadUrl(upgraded);
            Toast.makeText(this, "已开启 HTTPS，地址已切换为 " + upgraded
                    + "\n若服务器不支持 HTTPS，请关闭该开关或改回原地址", Toast.LENGTH_LONG).show();
        } else {
            Toast.makeText(this, next ? "已开启：明文 HTTP 地址将被拦截"
                    : "已关闭：允许内网明文 HTTP", Toast.LENGTH_SHORT).show();
        }
    }

    private void togglePush() {
        boolean next = !pushEnabled();
        prefs.edit().putBoolean(KEY_PUSH, next).apply();
        invalidateOptionsMenu();
        if (next) {
            startPush();
            Toast.makeText(this, "已开启消息推送（需服务端提供 push.php 接口）",
                    Toast.LENGTH_LONG).show();
        } else {
            PushPoller.stop();
            Toast.makeText(this, "已关闭消息推送", Toast.LENGTH_SHORT).show();
        }
    }

    private void clearSession() {
        CookieManager cookies = CookieManager.getInstance();
        cookies.removeAllCookies(null);
        cookies.flush();
        webView.clearCache(false);
        webView.reload();
        Toast.makeText(this, "已清除登录状态", Toast.LENGTH_SHORT).show();
    }

    // ---------------------------------------------------------------------
    // 服务器地址（不预置，首次由用户填写）
    // ---------------------------------------------------------------------

    private void showServerDialog(boolean cancelable) {
        String current = prefs.getString(KEY_SERVER, "");

        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(8), dp(20), 0);

        TextView tip = new TextView(this);
        tip.setText("填写报工系统访问地址，如 192.168.1.10:8080 或 https://bg.example.com。\n"
                + "手机需与服务器处于同一内网（或已连接 VPN）。"
                + (httpsOnly() ? "\n\n当前已开启「仅允许 HTTPS」，地址会自动按 https:// 处理。" : ""));
        tip.setTextSize(13);
        tip.setTextColor(0xFF666666);
        box.addView(tip);

        EditText input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        input.setSingleLine(true);
        input.setHint("192.168.1.10:8080");
        input.setText(current);
        if (current != null && !current.isEmpty()) {
            input.setSelection(current.length());
        }
        LinearLayout.LayoutParams inputLp = new LinearLayout.LayoutParams(MATCH, WRAP);
        inputLp.topMargin = dp(12);
        box.addView(input, inputLp);

        AlertDialog.Builder builder = new AlertDialog.Builder(this)
                .setTitle("服务器地址")
                .setView(box)
                .setCancelable(cancelable)
                .setPositiveButton("保存并打开", null);
        if (cancelable) {
            builder.setNegativeButton("取消", null);
        }
        AlertDialog dialog = builder.create();
        dialog.setOnShowListener(d -> dialog.getButton(AlertDialog.BUTTON_POSITIVE)
                .setOnClickListener(v -> {
                    String normalized = normalizeUrl(input.getText().toString());
                    if (normalized == null) {
                        input.setError("请输入有效的服务器地址");
                        return;
                    }
                    prefs.edit().putString(KEY_SERVER, normalized).apply();
                    dialog.dismiss();
                    errorView.setVisibility(View.GONE);
                    webView.loadUrl(normalized);
                    if (pushEnabled()) {
                        startPush();
                    }
                }));
        dialog.show();
    }

    private String normalizeUrl(String raw) {
        String s = raw == null ? "" : raw.trim();
        if (s.isEmpty() || s.contains(" ")) {
            return null;
        }
        if (!s.matches("(?i)^https?://.*")) {
            s = (httpsOnly() ? "https://" : "http://") + s;
        }
        if (httpsOnly() && s.toLowerCase(Locale.ROOT).startsWith("http://")) {
            s = "https://" + s.substring(7);
        }
        String path = s;
        int query = s.indexOf('?');
        if (query >= 0) {
            path = s.substring(0, query);
        }
        if (path.endsWith(".html") || path.endsWith(".php") || path.endsWith("/")) {
            return s;
        }
        return s + "/";
    }

    private void showAbout() {
        new AlertDialog.Builder(this)
                .setTitle("使用说明")
                .setMessage("本应用是报工系统的手机客户端，页面与数据均由服务器提供。\n\n"
                        + "1. 首次打开填写服务器地址（右上角菜单可随时修改）。\n"
                        + "2. 手机与服务器需在同一内网；外网访问请先连 VPN 隧道。\n"
                        + "3. 拍照识别、附件上传会调用系统相机或文件选择器。\n"
                        + "4. CSV / 备份文件下载后保存在手机「下载」目录，带进度通知。\n"
                        + "5. 菜单可开启「仅允许 HTTPS」「消息推送」，并进入「VPN 隧道设置」。\n"
                        + "6. 遇到白屏或报错，可用菜单中的「重新加载」「清除登录状态」。")
                .setPositiveButton("知道了", null)
                .show();
    }

    // ---------------------------------------------------------------------
    // 生命周期
    // ---------------------------------------------------------------------

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (webView != null) {
            webView.onPause();
        }
        CookieManager.getInstance().flush();
        PushPoller.stop();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.onResume();
        }
        if (pushEnabled()) {
            startPush();
        }
    }

    @Override
    protected void onDestroy() {
        if (fileCallback != null) {
            fileCallback.onReceiveValue(null);
            fileCallback = null;
        }
        if (networkMonitor != null) {
            networkMonitor.unregister();
            networkMonitor = null;
        }
        PushPoller.stop();
        if (webView != null) {
            webView.setWebChromeClient(null);
            webView.loadUrl("about:blank");
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    private int dp(float value) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, value,
                getResources().getDisplayMetrics());
    }
}
