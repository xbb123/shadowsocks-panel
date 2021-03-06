<?php
/**
 * SS-Panel
 * A simple Shadowsocks management system
 * Author: Sendya <18x@loacg.com>
 */
namespace Controller;

use Helper\Mailer;
use Model\Invite;
use Model\User;
use Model\Message as MessageModel;

use Core\Template;
use Helper\Utils;
use Helper\Option;
use Helper\Encrypt;

class Auth
{

    public function index()
    {
        header("Location: /auth/login");
    }

    /**
     * Login
     *
     * @JSON
     */
    public function login()
    {
        /**
         * 1. 判断用户是否已经登录,
         *      若已经登录,则直接跳转到控制面板(仪表盘)中.
         * 2. 加载登录页面模板,进入登录页面.
         */
        $user = User::getCurrent();
        if ($user->uid) {
            header("Location:/member");
        } else {
            if (isset($_REQUEST['email']) && isset($_REQUEST['passwd'])) {
                $result = array('error' => 1, 'message' => '账户不存在啊喂!');
                $email = htmlspecialchars(trim($_REQUEST['email']));
                $passwd = htmlspecialchars(trim($_REQUEST['passwd']));
                $remember_me = htmlspecialchars(trim($_REQUEST['remember_me']));

                $user = User::getUserByEmail($email);

                if ($user) {
                    if ($user->verifyPassword($passwd)) {
                        $result['error'] = 0;
                        $result['message'] = '登录成功,即将跳转到 &gt;仪表盘';

                        $remember_me == 'week' ? $ext = 3600 * 24 * 7 : $ext = 3600;
                        $expire = time() + $ext;
                        $token = md5($user->uid . ":" . $user->email . ":" . $user->passwd . ":" . $expire . ":" . COOKIE_KEY);
                        setcookie("uid", base64_encode(Encrypt::encode($user->uid, ENCRYPT_KEY)), $expire, "/");
                        setcookie("expire", base64_encode(Encrypt::encode($expire, ENCRYPT_KEY)), $expire, "/");
                        setcookie("token", base64_encode(Encrypt::encode($token, ENCRYPT_KEY)), $expire, "/");

                        $_SESSION['currentUser'] = $user;
                    } else {
                        $result['message'] = "账户名或密码错误, 请检查后再试!";
                    }
                }

                return $result;
            } else {
                $data['globalMessage'] = MessageModel::getGlobalMessage();
                Template::setContext($data);
                Template::setView('panel/login');
            }
        }
    }

    /**
     * 锁屏
     * @JSON
     */
    public function lockScreen()
    {
        // TODO -- 这个功能可能会弃用
        // 2016-04-09
    }

    public function logout()
    {
        setcookie("uid", '', time() - 3600, "/");
        setcookie("expire", '', time() - 3600, "/");
        setcookie("token", '', time() - 3600, "/");
        $_SESSION['currentUser'] = null;
        header("Location:/");
    }

    /**
     * @JSON
     */
    public function register()
    {
        $result = array('error' => 1, 'message' => '注册失败');
        $email = strtolower(trim($_POST['r_email']));
        $userName = trim($_POST['r_user_name']);
        $passwd = trim($_POST['r_passwd']);
        $repasswd = trim($_POST['r_passwd2']);
        $inviteCode = trim($_POST['r_invite']);
        $invite = Invite::getInviteByInviteCode($inviteCode); //校验 invite 是否可用
        if ($invite->status != 0 || $invite == null || empty($invite)) {
            $result['message'] = '邀请码不可用';
        } else {
            if ($repasswd != $passwd) {
                $result['message'] = '两次密码输入不一致';
            } else {
                if (strlen($passwd) < 6) {
                    $result['message'] = '密码太短,至少8字符';
                } /* else if (strlen($userName) < 4) {
            $result['message'] = '昵称太短,至少2中文字符或6个英文字符';
        }*/ else {
                    if ($chkEmail = Utils::mailCheck($email)) {
                        $result['message'] = $chkEmail;
                    } else {
                        $user = new User();
                        $user->email = $email;
                        if ($userName == null) // 如果用户名没填写，则使用email当用户名
                        {
                            $userName = $email;
                        }

                        $user->nickname = $userName;

                        // LEVEL 从数据库中获取
                        $custom_transfer_level = json_decode(Option::get('custom_transfer_level'), true);

                        // 定义邀请码套餐与流量单位
                        $transferNew = Utils::GB * intval($custom_transfer_level[$invite->plan]);

                        $user->transfer = $transferNew;
                        $user->invite = $inviteCode;
                        $user->plan = $invite->plan;// 将邀请码的账户类型设定到注册用户上.
                        $user->regDateLine = time();
                        $user->lastConnTime = $user->regDateLine;
                        $user->sspwd = Utils::randomChar();
                        $user->payTime = time(); // 注册时支付时间
                        $user_test_day = Option::get('user_test_day') ?: 7;
                        $user->expireTime = time() + (3600 * 24 * intval($user_test_day)); // 到期时间

                        $user->port = Utils::getNewPort(); // 端口号
                        $user->setPassword($passwd);
                        $user->save();

                        $invite->reguid = $user->uid;
                        $invite->regDateLine = $user->regDateLine;
                        $invite->status = 1; // -1过期 0-未使用 1-已用
                        $invite->inviteIp = Utils::getUserIP();
                        $invite->save();

                        if (null != $user->uid && 0 != $user->uid) {
                            $result['error'] = 0;
                            $result['message'] = '注册成功';
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * @JSON
     * @throws \Core\Error
     */
    public function forgePwd()
    {
        $result = array('error' => 1, 'message' => '请求找回密码失败，请刷新页面重试。');
        $siteName = SITE_NAME;

        if (isset($_POST['email']) && $_POST['email'] != '') {

            $user = User::getUserByEmail(htmlspecialchars(trim($_POST['email'])));
            if (!$user) {
                return $result;
            }
            $user->lastFindPasswdTime = time();
            if ($user->lastFindPasswdCount != 0 && $user->lastFindPasswdCount > 2) {
                $result['message'] = '找回密码重试次数已达上限!';
                return $result;
            }

            $code = Utils::randomChar(10);
            $forgePwdCode['code'] = $code;
            $forgePwdCode['time'] = time();

            $user->forgePwdCode = json_encode($forgePwdCode);
            $content = Option::get('custom_mail_forgePassword_content');
            $params = [
                'code'      => $code,
                'nickname'  => $user->nickname,
                'email'     => $user->email,
                'useTraffic'=> Utils::flowAutoShow($user->flow_up+$user->flow_down),
                'transfer'  => Utils::flowAutoShow($user->transfer),
                'expireTime'=> date('Y-m-d H:i:s', $user->expireTime)
            ];
            $content = Utils::placeholderReplace($content, $params);

            $mailer = Mailer::getInstance();
            $mail = new \Model\Mail();
            $mail->to = $user->email;
            $mail->subject = "[" . SITE_NAME . "] Password Recovery";
            $mail->content = $content;
            $mailer->toQueue(true); // 添加到邮件列队
            $isOk = $mailer->send($mail);

            $user->save();

            $result['uid'] = $user->uid;
            if ($isOk) {
                $result['message'] = '验证代码已经发送到该注册邮件地址，请注意查收!<br/>请勿关闭本页面，您还需要验证码来验证您的账户所有权才可重置密码！！';
                $result['error'] = 0;
            } else {
                $result['message'] = '邮件发送失败, 请联系管理员检查邮件系统设置！';
                $result['error'] = 1;
            }


            return $result;
        } else {
            if ($_POST['code'] != '' && $_POST['uid'] != '') {
                $uid = $_POST['uid'];
                $code = trim($_POST['code']);
                $user = User::GetUserByUserId(trim($uid));
                $forgePwdCode = json_decode($user->forgePwdCode, true);

                // forgePwdCode.length > 1 且 验证码一样 且 时间不超过600秒(10分钟)
                if (count($forgePwdCode) > 1 && $forgePwdCode['code'] == $code && (time() - intval($forgePwdCode['time'])) < 600) {
                    $newPassword = Utils::randomChar(10);
                    $user->setPassword($newPassword);

                    $user->lastFindPasswdCount = 0;
                    $user->lastFindPasswdTime = 0;
                    $user->save();

                    $content = Option::get('custom_mail_forgePassword_content_2');
                    $params = [
                        'code'      => $code,
                        'newPassword'=> $newPassword,
                        'nickname'  => $user->nickname,
                        'email'     => $user->email,
                        'useTraffic'=> Utils::flowAutoShow($user->flow_up+$user->flow_down),
                        'transfer'  => Utils::flowAutoShow($user->transfer),
                        'expireTime'=> date('Y-m-d H:i:s', $user->expireTime)
                    ];
                    $content = Utils::placeholderReplace($content, $params);

                    $mailer = Mailer::getInstance();
                    $mail = new \Model\Mail();
                    $mail->to = $user->email;
                    $mail->subject = "[" . SITE_NAME . "] Your new Password";
                    $mail->content = $content;
                    $mailer->toQueue(true); // 添加到邮件列队
                    $isOk = $mailer->send($mail);
                    if ($isOk) {
                        $result['message'] = '新密码已经发送到该账户邮件地址，请注意查收!<br/> 并且请在登录后修改密码！';
                        $result['error'] = 0;
                    } else {
                        $result['message'] = '邮件发送失败, 请联系管理员检查邮件系统设置！';
                        $result['error'] = 1;
                    }


                } else {
                    $result['message'] = '验证码已经超时或者 验证码填写不正确。请再次确认';
                    $result['error'] = -1;
                }
                return $result;
            } else {
                Template::putContext('user', User::getCurrent());
                Template::setView('home/forgePwd');
            }
        }

        return $result;
    }

}
