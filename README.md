# 終極電商（Ultimate E-commerce）

WooCommerce 擴充外掛，由 [NiBill](https://nibill-studio.com/) 開發維護。
版本資訊見 `ultimate-ecommerce.php`；使用者變更紀錄見 [readme.txt](readme.txt)。

## 功能

- 會員分級、生日禮與升等禮。
- 個人推薦碼、分享連結與推薦人首單紅利獎勵（預設 0 點）。
- 動態折扣、免運、贈品、購物車及商品頁加購。
- 優惠券卡片、紅利點數、商品兌換與儲值金。
- 台灣地址連動、超商取貨與訂單物流管理。
- 會員自助退換貨／取消訂單申請、管理員審核及綠界信用卡退刷。
- 蝦皮商品與訂單串接。

功能由後台開關與設定控制；需要 WooCommerce。前後台使用 jQuery。
儲值金與退款流程涉及訂單狀態與帳本，修改後應驗證對應業務情境。

推薦碼在「紅利點數 → 推薦碼」啟用，會員中心「會員權益」內即顯示推薦連結、複製按鈕與推薦人數，不再有獨立推薦碼頁籤。
朋友點擊專屬連結後，同一瀏覽器 30 天內建立新帳號會自動綁定，不需輸入推薦碼；以首次有效連結為準。
清除 Cookie、跨裝置或來源過期無法綁定。頁面快取／CDN 須排除含 `ref` 參數的入口。
推薦碼與獎勵均由紅利點數模組控制；停用點數模組會停止新綁定與發獎，保留設定與紀錄。
既有獎勵在取消或全額退款時仍會追回，部分退款保留。
推薦關係目前支援 WooCommerce／Blocksy 註冊與傳統結帳建立帳號。

後台設定直接顯示影響操作結果的提示，較長的補充內容可展開「詳細說明」查看。

## 程式結構

| 目錄／檔案 | 用途 |
| --- | --- |
| `ultimate-ecommerce.php` | 外掛入口、位置常數與模組載入 |
| `includes/helpers.php`、`includes/init.php` | 共用設定、初始化與 hook |
| `includes/modules/` | 電商業務模組 |
| `includes/admin/` | 後台設定與頁面 |
| `assets/` | JavaScript、CSS 與圖示 |
| `vendor/` | 第三方更新檢查程式碼 |
| `build.sh` | 安裝包打包與內容檢查 |

## 模組互通檢查

[模組互通檢查報告](docs/module-interactions-audit.md) 記錄驗證範圍、跨模組問題與點數交易／併發修正。

## 開發與驗證

本機開發文件 [CLAUDE.md](CLAUDE.md) 說明架構、業務限制與維護慣例；
[.dev-tools/README.md](.dev-tools/README.md) 說明回歸、後台渲染與資產檢查。
這兩份文件與開發工具不提交至公開倉庫，也不隨安裝包出貨。

開發工具支援 `TWSHOP_PHP`、`TWSHOP_MYSQL_SOCKET` 與 `TWSHOP_WP_ROOT` 環境變數。
依修改範圍執行語法檢查、對應回歸與渲染比對；失敗時工具回傳非零狀態。
純邏輯回歸可直接執行 `.dev-tools/optimization-regression.php`，不需要 WordPress 或資料庫。

## 打包

```bash
./build.sh
```

預設輸出 `/Volumes/work/外掛開發/ultimate-ecommerce.zip`，需先掛載對應磁碟。
腳本會排除版本控制、開發工具、Markdown 與內部設定，並驗證 zip 內容。
修改版本時同步更新 `readme.txt`；打包與發布是分開的步驟。
