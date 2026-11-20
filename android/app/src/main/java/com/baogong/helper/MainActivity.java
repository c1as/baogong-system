package com.baogong.helper;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
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

import androidx.core.content.FileProvider;

import java.io.File;
import java.io.IOException;

public class MainActivity extends Activity {

    private static final String PREFS = "bg_shell";
    private static final String KEY_SERVER = "server_url";

    private static final int MENU_SERVER = 1;
    private static final int MENU_RELOAD = 2;
    private static final int MENU_LOGOUT = 3;
    private static final int MENU_ABOUT = 4;

    private static final int REQ_PICK = 1001;
    private static final int REQ_DL_PERM = 1002;

    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    private WebView webView;
    private View errorView;
    private TextView errorText;
    private SharedPreferences prefs;

    private ValueCallback<Uri[]> fileCallback;
    private Uri cameraUri;
    private File cameraFile;

    private String pendingUrl;
    private String pendingUserAgent;
    private String pendingDisposition;
    private String pendingMime;

    private int dp(float value) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, value,
                getResources().getDisplayMetrics());
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        prefs = getSharedPreferences(PREFS, MODE_PRIVATE);

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptFileSchemeCookies(true);

        FrameLayout root = new FrameLayout(this);

        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(MATCH, MATCH));
        configureWebView();

        errorView = buildErrorView();
        errorView.setVisibility(View.GONE);
        root.addView(errorView, new FrameLayout.LayoutParams(MATCH, MATCH));

        setContentView(root);
        setTitle("报工助手");

        String url = prefs.getString(KEY_SERVER, "");
        if (url == null || url.isEmpty()) {
            showServerDialog(false);
        } else {
            webView.loadUrl(url);
        }
    }

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
                String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase();
                if ("http".equals(scheme) || "https".equals(scheme)
                        || "about".equals(scheme) || "data".equals(scheme) || "blob".equals(scheme)) {
                    return false;
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                } catch (ActivityNotFoundException e) {
                    Toast.makeText(MainActivity.this, "没有可打开该链接的应用", Toast.LENGTH_SHORT).show();
                }
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                errorView.setVisibility(View.GONE);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    showError("无法连接服务器\n\n" + request.getUrl() + "\n" + error.getDescription()
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
                    Toast.makeText(MainActivity.this, "没有可用的文件选择器", Toast.LENGTH_SHORT).show();
                    return false;
                }
            }
        });

        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            String mime = (mimeType == null || mimeType.isEmpty()) ? null : mimeType;
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q
                    && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE)
                    != PackageManager.PERMISSION_GRANTED) {
                pendingUrl = url;
                pendingUserAgent = userAgent;
                pendingDisposition = contentDisposition;
                pendingMime = mime;
                requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_DL_PERM);
                return;
            }
            enqueueDownload(url, userAgent, contentDisposition, mime);
        });
    }

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

    private void showError(String message) {
        if (errorText == null || errorView == null) {
            return;
        }
        errorText.setText(message);
        errorView.setVisibility(View.VISIBLE);
        errorView.bringToFront();
    }

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
        boolean wantsImage = accept.isEmpty() || accept.toLowerCase().contains("image");

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
            cameraUri = FileProvider.getUriForFile(this, getPackageName() + ".fileprovider", cameraFile);
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
            // 取消拍照时清理临时文件
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

    private void enqueueDownload(String url, String userAgent, String contentDisposition, String mime) {
        try {
            String fileName = URLUtil.guessFileName(url, contentDisposition, mime);
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            String cookie = CookieManager.getInstance().getCookie(url);
            if (cookie != null && !cookie.isEmpty()) {
                request.addRequestHeader("Cookie", cookie);
            }
            if (userAgent != null && !userAgent.isEmpty()) {
                request.addRequestHeader("User-Agent", userAgent);
            }
            if (mime != null && !mime.isEmpty()) {
                request.setMimeType(mime);
            }
            request.setTitle(fileName);
            request.setDescription("正在保存到手机下载目录");
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);

            DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (manager == null) {
                throw new IllegalStateException("下载服务不可用");
            }
            manager.enqueue(request);
            Toast.makeText(this, "已开始下载：" + fileName, Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            Toast.makeText(this, "下载失败：" + e.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != REQ_DL_PERM) {
            return;
        }
        if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            enqueueDownload(pendingUrl, pendingUserAgent, pendingDisposition, pendingMime);
        } else {
            Toast.makeText(this, "未授予存储权限，无法保存文件", Toast.LENGTH_LONG).show();
        }
        pendingUrl = null;
        pendingUserAgent = null;
        pendingDisposition = null;
        pendingMime = null;
    }

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, MENU_SERVER, 0, "服务器地址");
        menu.add(0, MENU_RELOAD, 1, "重新加载");
        menu.add(0, MENU_LOGOUT, 2, "清除登录状态");
        menu.add(0, MENU_ABOUT, 3, "使用说明");
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

    private void clearSession() {
        CookieManager cookies = CookieManager.getInstance();
        cookies.removeAllCookies(null);
        cookies.flush();
        webView.clearCache(false);
        webView.reload();
        Toast.makeText(this, "已清除登录状态", Toast.LENGTH_SHORT).show();
    }

    private void showServerDialog(boolean cancelable) {
        String current = prefs.getString(KEY_SERVER, "");

        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(8), dp(20), 0);

        TextView tip = new TextView(this);
        tip.setText("填写报工系统访问地址，如 192.168.1.10:8080 或 https://bg.example.com。\n手机需与服务器处于同一内网（或已连接 VPN）。");
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
                }));
        dialog.show();
    }

    private String normalizeUrl(String raw) {
        String s = raw == null ? "" : raw.trim();
        if (s.isEmpty() || s.contains(" ")) {
            return null;
        }
        if (!s.matches("(?i)^https?://.*")) {
            s = "http://" + s;
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
                        + "2. 手机与服务器需在同一内网；外网访问请使用 VPN 或公网地址。\n"
                        + "3. 拍照识别、附件上传会调用系统相机或文件选择器。\n"
                        + "4. CSV / 备份文件下载后保存在手机「下载」目录。\n"
                        + "5. 遇到白屏或报错，可用菜单中的「重新加载」「清除登录状态」。")
                .setPositiveButton("知道了", null)
                .show();
    }

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
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.onResume();
        }
    }

    @Override
    protected void onDestroy() {
        if (fileCallback != null) {
            fileCallback.onReceiveValue(null);
            fileCallback = null;
        }
        if (webView != null) {
            webView.setWebChromeClient(null);
            webView.loadUrl("about:blank");
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }
}
