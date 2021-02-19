# 標準のメールフォームに確認メール送信機能を追加する

This is the sample script of concrete5 default form block to send a confirmation email.

concrete5 に標準で同梱されているフォームブロックに、フォームの送信者へも確認メールを送ることができるようにするカスタマイズのコードと実装の仕方を紹介します。

## 内容

- Replyto: オプションをつけたメールアドレスに確認メールを送るカスタマイズ

## 免責 & License

サーバーが UTF8 の設定をしている UNIX 系のサーバーで設定する前提です。この実装による損害をうけても、一切の責任を負わないことに同意した方のみが使用してください。

動作は 8.5.4 で確認しています。

## セットアップ方法

[こちらの GitHub](https://github.com/katzueno/c5-form-support-Japanese-style) にあるファイルをダウンロード。

そして各ファイルを concrete5 の `application` フォルダ内の同じ階層に保存してください。

他にファイルをオーバーライドしている人は、間違って、既存の `application` ファイルやフォルダを削除しないようにしてください。

尚 `/application/mail/block_form_submission_user.php` が、ユーザーに送られる確認メールのテンプレートです。英語のままになっているので、日本語にする必要があれば、適宜、変更してください。

## 技術的な説明

### 確認メール

**[確認メール送信実装部分のカスタマイズ例](https://github.com/katzueno/c5-form-support-Japanese-style/commit/3a6542ca656d6c21943d22a4568571e367050dd3)** (GitHub上の Diff)

- メールアドレス入力項目で replyto の設定があるメールアドレスにも確認メールを送る判定を追加
- 管理者にメールを送信した後に、フォーム送信者へ確認メールを送る処理を追加
- フォーム送信者用のメールテンプレートを追加

### CSV を Shift JIS に変換するカスタマイズ例

concrete5 8.5.x 以降で「管理画面」-「システムと設定」-「ファイル」-「エクスポートオプション」にて BOM オプションを有効にすることによって、文字化けしなくなるため、以前あったカスタマイズを削除しました。

### コード

実際のコードは GitHub 上で公開しています。

[https://github.com/katzueno/c5-form-support-Japanese-style](https://github.com/katzueno/c5-form-support-Japanese-style)

## 宣伝

コンクリートファイブジャパン株式会社では、企業・団体様の concrete5 サイト制作や制作会社様のプロジェクトのサポートを行っています。また制作会社様向けに「インテグレートパートナー制度」を設け、印刷物では通常は使用禁止している concrete5 のロゴが使えるパートナー制度の運営も行っています。

http://concrete5.co.jp/

以上